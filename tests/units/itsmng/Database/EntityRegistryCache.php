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
