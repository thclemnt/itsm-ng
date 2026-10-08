<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use FilesystemIterator;
use LogicException;
use Psr\Log\AbstractLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use atoum\atoum\test;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Entity\Computer;
use itsmng\Database\Entity\Config;
use itsmng\Database\Entity\Entity;
use itsmng\Database\MappedRowProjection;
use itsmng\Database\MappingFingerprint;
use itsmng\Database\EntityRegistryCache as RegistryCache;
use itsmng\Database\Mapping\AttributeDriver;
use itsmng\Database\Mapping\MappedReference;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use itsmng\Database\Orm;
use itsmng\Database\Query\BitCount;
use itsmng\Database\Query\EpochSeconds;
use itsmng\Database\SerializedMetadataCache;
use stdClass;

/** Mapping/cache behavior without an application bootstrap or database connection. */
class EntityRegistryCache extends test
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
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }


    public function testPublicConfigurationsOwnMutableMappingState(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
        $platform = $connection->getDatabasePlatform();
        $first = Orm::configuration($platform);
        $second = Orm::configuration($platform);
        $this->object($second)->isNotIdenticalTo($first);
        $this->object($second->getMetadataDriverImpl())->isNotIdenticalTo($first->getMetadataDriverImpl());
        $this->object($second->getMetadataCache())->isNotIdenticalTo($first->getMetadataCache());
        $this->variable($first->getQueryCache())->isNull();
        $this->variable($second->getQueryCache())->isNull();
        $first->getMetadataDriverImpl()->setFileExtension('.custom');
        $this->string($second->getMetadataDriverImpl()->getFileExtension())->isNotIdenticalTo('.custom');
        $custom = new EntityManager($connection, $first);
        $custom->getEventManager()->addEventListener(Events::loadClassMetadata, new class () {
            public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
            {
                if ($event->getClassMetadata()->name === Config::class) {
                    $event->getClassMetadata()->setPrimaryTable(['name' => 'local_custom_config']);
                }
            }
        });
        $normal = new EntityManager($connection, $second);
        $this->string($custom->getClassMetadata(Config::class)->getTableName())->isIdenticalTo('local_custom_config');
        $this->string($normal->getClassMetadata(Config::class)->getTableName())->isIdenticalTo('glpi_configs');
        $first->addCustomNumericFunction('LOCAL_FUNCTION', BitCount::class);
        $second->addCustomNumericFunction('LOCAL_FUNCTION', EpochSeconds::class);
        $dql = 'SELECT LOCAL_FUNCTION(c.id) FROM ' . Config::class . ' c';
        $this->string($custom->createQuery($dql)->getSQL())->contains('BIT_COUNT(');
        $this->string($normal->createQuery($dql)->getSQL())->contains('UNIX_TIMESTAMP(');
        $first->addCustomNumericFunction('LOCAL_FUNCTION', EpochSeconds::class);
        $this->string($custom->createQuery($dql)->getSQL())->contains('UNIX_TIMESTAMP(');
        $this->boolean($connection->isConnected())->isFalse();
        $custom->clear();
        $normal->clear();
        $connection->close();
    }

    public function testScalarIdentifiersMatchBothProvidersAndIgnorePublicCustomization(): void
    {
        $previousCache = $GLOBALS['GLPI_CACHE'] ?? null;
        $model = new ReflectionProperty(EntityRegistry::class, 'model');
        $previousModel = $model->getValue();
        $model->setValue(null, null);
        $GLOBALS['GLPI_CACHE'] = new Psr16Cache(new ArrayAdapter(storeSerialized: false));
        try {
            $platform = new MySQLPlatform();
            $public = Orm::configuration($platform);
            $driver = $public->getMetadataDriverImpl();
            $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
            $manager = new EntityManager($connection, $public);
            $manager->getEventManager()->addEventListener(Events::loadClassMetadata, new class () {
                public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                {
                    $metadata = $event->getClassMetadata();
                    if ($metadata->name === Config::class) {
                        $metadata->fieldMappings['id']->type = 'string';
                        $metadata->setPrimaryTable(['name' => 'public_custom_config']);
                    }
                }
            });
            $this->string($manager->getClassMetadata(Config::class)->getTypeOfField('id'))->isIdenticalTo('string');
            $driver->setFileExtension('.public-custom-driver');
            $actual = EntityRegistry::scalarIdentifiers();
            $this->string($actual[Config::class]['type'])->isIdenticalTo('bigint');
            $this->string(EntityRegistry::tables()['glpi_configs'])->isIdenticalTo(Config::class);
            $this->object(Orm::configuration($platform)->getMetadataDriverImpl())->isNotIdenticalTo($driver);
            $this->string($driver->getFileExtension())->isIdenticalTo('.public-custom-driver');
            $this->boolean($connection->isConnected())->isFalse();
            $manager->clear();
            unset($manager);
            $connection->close();
            ksort($actual);
            foreach ([['pdo_mysql', '8.4.0'], ['pdo_mysql', '10.11.18-MariaDB'], ['pdo_pgsql', '16.0']] as [$driverName, $version]) {
                $connection = DriverManager::getConnection(['driver' => $driverName, 'serverVersion' => $version]);
                $platform = $connection->getDatabasePlatform();
                $configuration = Orm::configuration($platform);
                $configuration->setMetadataDriverImpl(new AttributeDriver([dirname((new ReflectionClass(EntityRegistry::class))->getFileName()) . '/Entity'], $platform));
                $configuration->setMetadataCache(new ArrayAdapter(storeSerialized: true));
                $manager = new EntityManager($connection, $configuration);
                $expected = [];
                $quote = $configuration->getQuoteStrategy();
                $quoteName = static fn (array $name): string => $name[1] ? $platform->quoteSingleIdentifier($name[0]) : $name[0];
                foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
                    $projection = EntityRegistry::componentCountMapping($metadata->getTableName());
                    if ($projection !== null) {
                        $this->string($quoteName($projection['table']))->isIdenticalTo($quote->getTableName($metadata, $platform));
                        foreach ($projection['fields'] as $property => $name) {
                            $this->string($quoteName($name))->isIdenticalTo($quote->getColumnName($property, $metadata, $platform));
                        }
                        $reference = EntityRegistry::discriminatedReferences($metadata->getTableName())['items_id'] ?? null;
                        foreach (array_keys($reference['selections'] ?? []) as $kind) {
                            $property = $metadata->name::referenceAssociation($kind);
                            $join = $metadata->associationMappings[$property]->joinColumns[0];
                            $this->string($quoteName($projection['subjects'][$property]))->isIdenticalTo($quote->getJoinColumnName($join, $metadata, $platform));
                        }
                    }
                    if (count($metadata->identifier) === 1 && $metadata->hasField($metadata->identifier[0])) {
                        $property = $metadata->identifier[0];
                        $expected[$metadata->name] = ['property' => $property,
                            'column' => $metadata->getColumnName($property), 'type' => $metadata->getTypeOfField($property)];
                    } else {
                        $this->boolean(isset($actual[$metadata->name]))->isFalse();
                    }
                }
                $entity = $manager->getClassMetadata(Entity::class);
                $types = $enums = [];
                foreach ($entity->fieldMappings as $mapping) {
                    $types[$mapping->columnName] = $mapping->type;
                    if ($mapping->enumType !== null) {
                        $enums[$mapping->columnName] = $mapping->enumType;
                    }
                }
                $this->array(EntityRegistry::fieldTypes('glpi_entities'))->isIdenticalTo($types);
                $this->array(EntityRegistry::fieldEnums('glpi_entities'))->isIdenticalTo($enums);
                ksort($expected);
                $this->array($actual)->isIdenticalTo($expected);
                $this->boolean($connection->isConnected())->isFalse();
                $manager->clear();
                unset($manager, $metadata);
                $connection->close();
                gc_collect_cycles();
            }
        } finally {
            $GLOBALS['GLPI_CACHE'] = $previousCache;
            $model->setValue(null, $previousModel);
        }
    }

    public function testScalarReferenceFactsRetainIdentifierValidationFallbacks(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '16.0']);
        $configuration = Orm::configuration($connection->getDatabasePlatform());
        $configuration->setMetadataCache(new ArrayAdapter(storeSerialized: true));
        $manager = new EntityManager($connection, $configuration);
        try {
            $source = $manager->getClassMetadata(Computer::class);
            $target = $manager->getClassMetadata(Entity::class);
            $facts = EntityRegistry::scalarIdentifiers();
            // A mismatched reference column must inspect real metadata and reject it.
            $mismatch = clone $source;
            $property = array_key_first($mismatch->associationMappings);
            $this->string($mismatch->associationMappings[$property]->targetEntity)->isIdenticalTo($target->name);
            $mismatch->associationMappings[$property] = clone $mismatch->associationMappings[$property];
            $mismatch->associationMappings[$property]->joinColumns[0] = clone $mismatch->associationMappings[$property]->joinColumns[0];
            $mismatch->associationMappings[$property]->joinColumns[0]->referencedColumnName = 'name';
            $this->exception(static fn () => new MappedRowProjection($manager, $mismatch, $facts))->isInstanceOf(LogicException::class);
            // No scalar fact is supplied for a composite or association identifier.
            unset($facts[$target->name]);
            $originalTarget = $target;
            $target = clone $target;
            $manager->getMetadataFactory()->setMetadataFor($target->name, $target);
            $target->identifier = ['id', 'name'];
            $target->isIdentifierComposite = true;
            $this->exception(static fn () => new MappedRowProjection($manager, $source, $facts))->isInstanceOf(MappingException::class);
            $association = array_key_first($target->associationMappings);
            $this->string($association)->isNotEmpty();
            $target->identifier = [$association];
            $target->isIdentifierComposite = false;
            $this->exception(static fn () => new MappedRowProjection($manager, $source, $facts))->isInstanceOf(LogicException::class);
            $manager->getMetadataFactory()->setMetadataFor($originalTarget->name, $originalTarget);
            $this->boolean($connection->isConnected())->isFalse();
        } finally {
            if (isset($originalTarget)) {
                $manager->getMetadataFactory()->setMetadataFor($originalTarget->name, $originalTarget);
            }
            $manager->clear();
            $connection->close();
        }
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
        $first = (new RegistryCache($cache, MappingFingerprint::forSource($this->root)))->load($build);
        $first['types']['name'] = 'changed by caller';
        $second = (new RegistryCache($cache, MappingFingerprint::forSource($this->root)))->load($build);
        $this->integer($builds)->isIdenticalTo(1);
        $this->string($second['types']['name'])->isIdenticalTo('string');
        $this->object($second['reference'])->isNotIdenticalTo($first['reference']);
        $this->variable($second['reference']->policy->kind)->isIdenticalTo(ReferenceKind::RootEntity);
        $cache->clear();
        (new RegistryCache($cache, MappingFingerprint::forSource($this->root)))->load($build);
        $this->integer($builds)->isIdenticalTo(2);
    }

    public function testContentChangesInvalidateEntitiesHelpersAndDependencyLock(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $builds = 0;
        $build = static function () use (&$builds): array {
            return ['generation' => ++$builds];
        };
        (new RegistryCache($cache, MappingFingerprint::forSource($this->root)))->load($build);
        foreach ([
            'src/Database/Entity/Record.php' => '<?php /* field length 200 */',
            'src/Database/Mapping/Driver.php' => '<?php /* driver version 2 */',
            'composer.lock' => 'dependency version two',
        ] as $relative => $content) {
            $file = $this->root . '/' . $relative;
            $mtime = filemtime($file);
            file_put_contents($file, $content);
            touch($file, $mtime); // same-size/same-mtime deployment still changes the key
            $value = (new RegistryCache($cache, MappingFingerprint::forSource($this->root)))->load($build);
            $this->integer($value['generation'])->isIdenticalTo($builds);
        }
        $this->integer($builds)->isIdenticalTo(4);
        unlink($this->root . '/src/Database/Entity/Record.php');
        (new RegistryCache($cache, MappingFingerprint::forSource($this->root)))->load($build);
        $this->integer($builds)->isIdenticalTo(5);
    }

    public function testDamagedCacheIsAMissAndDoesNotMaskMappingFailures(): void
    {
        $pool = new ArrayAdapter(storeSerialized: false);
        $cache = new Psr16Cache($pool);
        $builds = 0;
        $build = static function () use (&$builds): array {
            return ['generation' => ++$builds];
        };
        $registry = new RegistryCache($cache, MappingFingerprint::forSource($this->root));
        $registry->load($build);
        $key = array_key_first($pool->getValues());
        foreach (['a:0:{}', '2:' . str_repeat('0', 64) . ':a:0:{}', '2:' . hash('sha256', 'truncated') . ':truncated'] as $damaged) {
            $cache->set($key, $damaged);
            $value = $registry->load($build);
            $this->integer($value['generation'])->isIdenticalTo($builds);
        }
        $this->integer($builds)->isIdenticalTo(4);
        $unrecognized = serialize(['unexpected' => new RegistryCacheWakeupProbe()]);
        $cache->set($key, '2:' . hash('sha256', $unrecognized) . ':' . $unrecognized);
        $this->array($registry->load($build))->isIdenticalTo(['generation' => 5]);
        $this->integer(RegistryCacheWakeupProbe::$wakeups)->isIdenticalTo(0);
        $cache->clear();
        $this->exception(static fn () => $registry->load(static fn () => throw new LogicException('Invalid authoritative mapping')))
            ->isInstanceOf(LogicException::class)->hasMessage('Invalid authoritative mapping');
    }

    public function testValidationPreservesSharedArraysAndRejectsRecursiveOrUnknownValues(): void
    {
        $pool = new ArrayAdapter(storeSerialized: false);
        $cache = new Psr16Cache($pool);
        $registry = new RegistryCache($cache, MappingFingerprint::forSource($this->root));
        $shared = ['reference' => new MappedReference(
            'entity',
            'entities_id',
            'glpi_entities',
            new ReferencePolicy(ReferenceKind::RootEntity)
        ), 'flag' => true, 'empty' => null];
        $model = ['left' => &$shared, 'right' => &$shared, 'nested' => [[], ['zero' => 0, 'name' => '0']]];
        $builds = 0;
        $build = static function () use (&$builds, $model): array {
            ++$builds;
            return $model;
        };
        $registry->load($build);
        $key = array_key_first($pool->getValues());
        $warm = $registry->load($build);
        $this->integer($builds)->isIdenticalTo(1);
        $this->string(serialize($warm))->isIdenticalTo(serialize($model));
        $warm['left']['flag'] = false;
        $this->boolean($warm['right']['flag'])->isFalse();
        $this->boolean($registry->load($build)['left']['flag'])->isTrue();
        $this->integer($builds)->isIdenticalTo(1);

        $cycle = [];
        $cycle['self'] = &$cycle;
        $first = [];
        $second = ['back' => &$first];
        $first['next'] = &$second;
        foreach ([$cycle, $first, ['nested' => [['unknown' => new RegistryCacheWakeupProbe()]]]] as $invalid) {
            $bytes = serialize($invalid);
            $cache->set($key, '2:' . hash('sha256', $bytes) . ':' . $bytes);
            $before = $builds;
            $this->string(serialize($registry->load($build)))->isIdenticalTo(serialize($model));
            $this->integer($builds)->isIdenticalTo($before + 1);
            $this->integer(RegistryCacheWakeupProbe::$wakeups)->isIdenticalTo(0);
        }
    }

    public function testPackagedReleaseWithoutComposerManifestStillCaches(): void
    {
        unlink($this->root . '/composer.lock');
        $cache = new Psr16Cache(new ArrayAdapter());
        $builds = 0;
        $build = static function () use (&$builds): array {
            return ['generation' => ++$builds];
        };
        (new RegistryCache($cache, MappingFingerprint::forSource($this->root)))->load($build);
        (new RegistryCache($cache, MappingFingerprint::forSource($this->root)))->load($build);
        $this->integer($builds)->isIdenticalTo(1);
    }

    public function testCacheFailuresAndMissingSourceUseAuthoritativeMapping(): void
    {
        $cache = new class (new ArrayAdapter()) extends Psr16Cache {
            public function get($key, $default = null): mixed
            {
                throw new RuntimeException('Cache unavailable');
            }

            public function set($key, $value, $ttl = null): bool
            {
                throw new RuntimeException('Cache unavailable');
            }
        };
        $builds = 0;
        $build = static function () use (&$builds): array {
            return ['generation' => ++$builds];
        };
        $this->array((new RegistryCache($cache, MappingFingerprint::forSource($this->root)))->load($build))->isIdenticalTo(['generation' => 1]);
        rename($this->root . '/src/Database', $this->root . '/src/UnavailableDatabase');
        $this->array((new RegistryCache($cache, MappingFingerprint::forSource($this->root)))->load($build))->isIdenticalTo(['generation' => 2]);
    }

    public function testSerializedMetadataBridgePreservesFreshObjectsAndDeploymentNamespaces(): void
    {
        $memory = new ArrayAdapter(storeSerialized: false);
        $pool = new Psr16Cache($memory);
        $fingerprint = MappingFingerprint::forSource($this->root);
        $first = new SerializedMetadataCache($pool, 'metadata_' . $fingerprint);
        $metadata = new ClassMetadata(Config::class);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
        $this->boolean($first->save($first->getItem('config')->set($metadata)))->isTrue();
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
        $second = new SerializedMetadataCache($pool, 'metadata_' . $fingerprint);
        $hit = $second->getItem('config');
        $this->boolean($hit->isHit())->isTrue();
        $this->integer($hit->get()->generatorType)->isIdenticalTo(ClassMetadata::GENERATOR_TYPE_IDENTITY);
        $this->object($hit->get())->isNotIdenticalTo($first->getItem('config')->get());
        foreach ($memory->getValues() as $value) {
            $this->string($value);
        }
        $file = $this->root . '/src/Database/Mapping/Driver.php';
        $mtime = filemtime($file);
        file_put_contents($file, '<?php /* driver version 2 */');
        touch($file, $mtime);
        $rotated = new SerializedMetadataCache($pool, 'metadata_' . MappingFingerprint::forSource($this->root));
        $this->boolean($rotated->getItem('config')->isHit())->isFalse();
        $this->boolean($second->getItem('config')->isHit())->isTrue();
        $pool->clear();
        $this->boolean($second->getItem('config')->isHit())->isFalse();
    }

    public function testSerializedMetadataBridgeTreatsDamagedBytesAndBackendFailuresAsMisses(): void
    {
        $memory = new ArrayAdapter(storeSerialized: false);
        $pool = new Psr16Cache($memory);
        $cache = new SerializedMetadataCache($pool, 'metadata');
        $this->boolean($cache->save($cache->getItem('record')->set(new stdClass())))->isTrue();
        $key = array_key_first($memory->getValues());
        foreach (['truncated', new stdClass()] as $invalid) {
            $pool->set($key, $invalid);
            $this->boolean($cache->getItem('record')->isHit())->isFalse();
        }
        $this->boolean($cache->save($cache->getItem('null')->set(null)))->isTrue();
        $this->boolean($cache->getItem('null')->isHit())->isTrue();
        $this->variable($cache->getItem('null')->get())->isNull();
        $throwing = new class (new ArrayAdapter()) extends Psr16Cache {
            public function getMultiple($keys, $default = null): iterable
            {
                throw new RuntimeException('Cache unavailable');
            }
            public function setMultiple($values, $ttl = null): bool
            {
                throw new RuntimeException('Cache unavailable');
            }
        };
        $unavailable = new SerializedMetadataCache($throwing, 'metadata');
        $logger = new class () extends AbstractLogger {
            public array $levels = [];
            public function log($level, $message, array $context = []): void
            {
                $this->levels[] = $level;
            }
        };
        $unavailable->setLogger($logger);
        $this->boolean($unavailable->getItem('record')->isHit())->isFalse();
        $this->boolean($unavailable->save($unavailable->getItem('record')->set(new stdClass())))->isFalse();
        $this->array($logger->levels)->isIdenticalTo(['warning', 'warning', 'warning']);
    }

    public function testPublicTypesComeFromOwningDiscriminatorTargets(): void
    {
        $types = EntityRegistry::legacyTables();
        $this->string($types['Computer'])->isIdenticalTo('glpi_computers');
        $this->string($types['NetworkEquipment'])->isIdenticalTo('glpi_networkequipments');
        $this->string($types['Peripheral'])->isIdenticalTo('glpi_peripherals');
        $this->string($types['Phone'])->isIdenticalTo('glpi_phones');
        $this->string($types['Printer'])->isIdenticalTo('glpi_printers');
        $this->string($types['Enclosure'])->isIdenticalTo('glpi_enclosures');
        $this->string($types['Item_DeviceMemory'])->isIdenticalTo('glpi_items_devicememories');
        $this->string($types['Item_DeviceGeneric'])->isIdenticalTo('glpi_items_devicegenerics');
        $components = array_filter($types, static fn (string $table): bool => str_starts_with($table, 'glpi_items_device'));
        $this->array($components)->hasSize(17);
        $this->boolean(isset($types['mock\\Computer']))->isFalse();
        $this->boolean(isset($types['mock\\Item_DeviceMemory']))->isFalse();
        $declared = [];
        foreach (EntityRegistry::tables() as $table => $class) {
            foreach (EntityRegistry::discriminatedReferences($table) as $reference) {
                if (($reference['discriminator'] ?? null) !== 'itemtype') {
                    continue;
                }
                foreach ($reference['selections'] as $kind => $selection) {
                    if (isset($declared[$kind])) {
                        $this->string($selection['target'])->isIdenticalTo($declared[$kind]);
                    }
                    $declared[$kind] = $selection['target'];
                }
            }
        }
        ksort($declared);
        ksort($types);
        $this->array($types)->isIdenticalTo($declared);
    }

    public function testRealRegistryColdAndWarmProjectionsAreIdentical(): void
    {
        $previous = $GLOBALS['GLPI_CACHE'] ?? null;
        $model = new ReflectionProperty(EntityRegistry::class, 'model');
        $previousModel = $model->getValue();
        $pool = new ArrayAdapter(storeSerialized: false);
        $GLOBALS['GLPI_CACHE'] = new Psr16Cache($pool);
        $snapshot = static fn (): array => [EntityRegistry::tables(), EntityRegistry::legacyTables(), EntityRegistry::relations(), EntityRegistry::lifecycleRelations(), EntityRegistry::nativeTimestamps(), EntityRegistry::booleanColumns(), EntityRegistry::references('glpi_tickets'), EntityRegistry::fieldTypes('glpi_entities'), EntityRegistry::fieldEnums('glpi_entities')];
        try {
            $model->setValue(null, null);
            $cold = serialize($snapshot());
            $this->array($pool->getValues())->hasSize(1);
            foreach ($pool->getValues() as $value) {
                $this->string($value);
            }
            $model->setValue(null, null);
            $this->string(serialize($snapshot()))->isIdenticalTo($cold);
            // A valid old-format payload has no enum facts and must be rebuilt.
            $oldModel = $model->getValue();
            unset($oldModel['enums']);
            $bytes = serialize($oldModel);
            $key = array_key_first($pool->getValues());
            $GLOBALS['GLPI_CACHE']->set($key, '1:' . hash('sha256', $bytes) . ':' . $bytes);
            $model->setValue(null, null);
            $this->string(serialize($snapshot()))->isIdenticalTo($cold);
            $this->string($GLOBALS['GLPI_CACHE']->get($key))->startWith('2:');
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
