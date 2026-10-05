<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use itsmng\Database\EntityRegistry;
use itsmng\Database\EntityRegistryCache as RegistryCache;
use itsmng\Database\Mapping\MappedReference;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/** Mapping/cache behavior without an application bootstrap or database connection. */
class EntityRegistryCache extends \atoum\atoum\test
{
    private string $root;

    public function beforeTestMethod($method): void
    {
        $this->root = sys_get_temp_dir() . '/itsm-registry-cache-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src/Database/Entity', 0700, true);
        mkdir($this->root . '/src/Database/Mapping', 0700);
        file_put_contents($this->root . '/composer.lock', 'dependency version one');
        file_put_contents($this->root . '/src/Database/Entity/Record.php', '<?php /* field length 100 */');
        file_put_contents($this->root . '/src/Database/Mapping/Driver.php', '<?php /* driver version 1 */');
    }

    public function afterTestMethod($method): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testWarmLoadUsesSerializedValuesAndConfiguredPoolClearInvalidates(): void
    {
        // A cache that itself retains objects must still receive only serialized
        // projections, and cannot leak a caller's edits into the next request.
        $cache = new Psr16Cache(new ArrayAdapter(storeSerialized: false));
        $builds = 0;
        $build = static function () use (&$builds): array {
            ++$builds;
            return ['types' => ['name' => 'string'], 'reference' => new MappedReference('entity', 'entities_id', 'glpi_entities', new ReferencePolicy(ReferenceKind::RootEntity))];
        };
        $first = (new RegistryCache($cache, $this->root))->load($build);
        $first['types']['name'] = 'changed by caller';
        $second = (new RegistryCache($cache, $this->root))->load($build);
        $this->integer($builds)->isIdenticalTo(1);
        $this->string($second['types']['name'])->isIdenticalTo('string');
        $this->object($second['reference'])->isNotIdenticalTo($first['reference']);
        $this->variable($second['reference']->policy->kind)->isIdenticalTo(ReferenceKind::RootEntity);
        $cache->clear();
        (new RegistryCache($cache, $this->root))->load($build);
        $this->integer($builds)->isIdenticalTo(2);
    }

    public function testContentChangesInvalidateEntitiesHelpersAndDependencyLock(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $builds = 0;
        $build = static function () use (&$builds): array { return ['generation' => ++$builds]; };
        (new RegistryCache($cache, $this->root))->load($build);
        foreach ([
            'src/Database/Entity/Record.php' => '<?php /* field length 200 */',
            'src/Database/Mapping/Driver.php' => '<?php /* driver version 2 */',
            'composer.lock' => 'dependency version two',
        ] as $relative => $content) {
            $file = $this->root . '/' . $relative;
            $mtime = filemtime($file);
            file_put_contents($file, $content);
            touch($file, $mtime); // same-size/same-mtime deployment still changes the key
            $value = (new RegistryCache($cache, $this->root))->load($build);
            $this->integer($value['generation'])->isIdenticalTo($builds);
        }
        $this->integer($builds)->isIdenticalTo(4);
        unlink($this->root . '/src/Database/Entity/Record.php');
        (new RegistryCache($cache, $this->root))->load($build);
        $this->integer($builds)->isIdenticalTo(5);
    }

    public function testDamagedCacheIsAMissAndDoesNotMaskMappingFailures(): void
    {
        $pool = new ArrayAdapter(storeSerialized: false);
        $cache = new Psr16Cache($pool);
        $builds = 0;
        $build = static function () use (&$builds): array { return ['generation' => ++$builds]; };
        $registry = new RegistryCache($cache, $this->root);
        $registry->load($build);
        $key = array_key_first($pool->getValues());
        foreach (['a:0:{}', '1:' . str_repeat('0', 64) . ':a:0:{}', '1:' . hash('sha256', 'truncated') . ':truncated'] as $damaged) {
            $cache->set($key, $damaged);
            $value = $registry->load($build);
            $this->integer($value['generation'])->isIdenticalTo($builds);
        }
        $this->integer($builds)->isIdenticalTo(4);
        $unrecognized = serialize(['unexpected' => new RegistryCacheWakeupProbe()]);
        $cache->set($key, '1:' . hash('sha256', $unrecognized) . ':' . $unrecognized);
        $this->array($registry->load($build))->isIdenticalTo(['generation' => 5]);
        $this->integer(RegistryCacheWakeupProbe::$wakeups)->isIdenticalTo(0);
        $cache->clear();
        $this->exception(static fn () => $registry->load(static fn () => throw new \LogicException('Invalid authoritative mapping')))
            ->isInstanceOf(\LogicException::class)->hasMessage('Invalid authoritative mapping');
    }

    public function testPackagedReleaseWithoutComposerManifestStillCaches(): void
    {
        unlink($this->root . '/composer.lock');
        $cache = new Psr16Cache(new ArrayAdapter());
        $builds = 0;
        $build = static function () use (&$builds): array { return ['generation' => ++$builds]; };
        (new RegistryCache($cache, $this->root))->load($build);
        (new RegistryCache($cache, $this->root))->load($build);
        $this->integer($builds)->isIdenticalTo(1);
    }

    public function testCacheFailuresAndMissingSourceUseAuthoritativeMapping(): void
    {
        $cache = new class (new ArrayAdapter()) extends Psr16Cache {
            public function get($key, $default = null): mixed
            {
                throw new \RuntimeException('Cache unavailable');
            }

            public function set($key, $value, $ttl = null): bool
            {
                throw new \RuntimeException('Cache unavailable');
            }
        };
        $builds = 0;
        $build = static function () use (&$builds): array { return ['generation' => ++$builds]; };
        $this->array((new RegistryCache($cache, $this->root))->load($build))->isIdenticalTo(['generation' => 1]);
        rename($this->root . '/src/Database', $this->root . '/src/UnavailableDatabase');
        $this->array((new RegistryCache($cache, $this->root))->load($build))->isIdenticalTo(['generation' => 2]);
    }

    public function testRealRegistryColdAndWarmProjectionsAreIdentical(): void
    {
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $model = new \ReflectionProperty(EntityRegistry::class, 'model');
        $previousModel = $model->getValue();
        $pool = new ArrayAdapter(storeSerialized: false);
        $GLOBALS['GLPI_CACHE'] = new Psr16Cache($pool);
        $snapshot = static fn (): array => [EntityRegistry::tables(), EntityRegistry::relations(), EntityRegistry::lifecycleRelations(), EntityRegistry::nativeTimestamps(), EntityRegistry::booleanColumns(), EntityRegistry::references('glpi_tickets')];
        try {
            $model->setValue(null, null);
            $cold = serialize($snapshot());
            $this->array($pool->getValues())->hasSize(1);
            foreach ($pool->getValues() as $value) {
                $this->string($value);
            }
            $model->setValue(null, null);
            $this->string(serialize($snapshot()))->isIdenticalTo($cold);
            $GLOBALS['GLPI_CACHE']->clear();
            $model->setValue(null, null);
            $this->string(serialize($snapshot()))->isIdenticalTo($cold);
        } finally {
            $GLOBALS['GLPI_CACHE'] = $previous;
            $model->setValue(null, $previousModel);
        }
    }
}

final class RegistryCacheWakeupProbe
{
    public static int $wakeups = 0;

    public function __wakeup(): void
    {
        ++self::$wakeups;
    }
}
