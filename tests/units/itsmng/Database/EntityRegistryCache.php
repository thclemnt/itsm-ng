<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use Composer\InstalledVersions;
use itsmng\Database\MySQLManagedConnection;
use itsmng\Database\Type\FixedStringType;
use itsmng\Database\Type\ClockTimeType;
use itsmng\Database\Entity\User;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\DBAL\Types\Type as DbalType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use LogicException;
use Psr\Log\AbstractLogger;
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
use itsmng\Database\Entity\ComputerItem;
use itsmng\Database\Entity\ComputerVirtualMachine;
use itsmng\Database\Entity\Infocom;
use itsmng\Database\Entity\ITILFollowup;

/** Mapping/cache behavior without an application bootstrap or database connection. */
class EntityRegistryCache extends test
{
    public function testScalarTypeRegistrationPreservesExistingTypes(): void
    {
        Orm::registerTypes();
        $registry = DbalType::getTypeRegistry();
        $originals = [
            ClockTimeType::NAME => DbalType::getType(ClockTimeType::NAME),
            FixedStringType::NAME => DbalType::getType(FixedStringType::NAME),
        ];
        Orm::registerTypes();
        foreach ($originals as $name => $type) {
            $this->object(DbalType::getType($name))->isIdenticalTo($type);
        }
        try {
            $custom = [];
            foreach ($originals as $name => $type) {
                $registry->override($name, $custom[$name] = new StringType());
            }
            Orm::registerTypes();
            Orm::configuration(new MySQLPlatform());
            foreach ($custom as $name => $type) {
                $this->object(DbalType::getType($name))->isIdenticalTo($type);
            }
        } finally {
            foreach ($originals as $name => $type) {
                $registry->override($name, $type);
            }
        }
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

        $parameters = ['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0', 'wrapperClass' => MySQLManagedConnection::class];
        $application = DriverManager::getConnection($parameters);
        $otherRoute = DriverManager::getConnection($parameters);
        $canonical = null;
        try {
            Orm::withConnection($application, function (EntityManager $manager) use ($application, &$canonical): void {
                $canonical = $manager;
                $this->object($manager->getConfiguration()->getQueryCache())->isInstanceOf(ArrayAdapter::class);
                $reference = $manager->getReference(Config::class, 1);
                $this->boolean($manager->contains($reference))->isTrue();
                Orm::withConnection($application, function (EntityManager $nested) use ($manager): void {
                    $this->object($nested)->isNotIdenticalTo($manager);
                    $this->variable($nested->getConfiguration()->getQueryCache())->isNull();
                });
                $this->boolean($manager->contains($reference))->isTrue('Nested completion cannot clear its parent unit of work');
            });
            Orm::withConnection($application, function (EntityManager $manager) use (&$canonical, $otherRoute): void {
                $this->object($manager)->isIdenticalTo($canonical);
                $this->integer($manager->getUnitOfWork()->size())->isIdenticalTo(0);
                Orm::withConnection($otherRoute, function (EntityManager $other) use ($manager): void {
                    $this->object($other)->isNotIdenticalTo($manager);
                    $this->object($other->getConnection())->isNotIdenticalTo($manager->getConnection());
                    $this->object($other->getConfiguration()->getQueryCache())->isNotIdenticalTo($manager->getConfiguration()->getQueryCache());
                });
                $manager->close();
            });
            Orm::withConnection($application, function (EntityManager $manager) use (&$canonical): void {
                $this->object($manager)->isNotIdenticalTo($canonical);
                $this->object($manager->getConfiguration()->getQueryCache())->isNotIdenticalTo($canonical->getConfiguration()->getQueryCache());
                $this->boolean($manager->isOpen())->isTrue();
                $canonical = $manager;
            });
            $application->close();
            Orm::withConnection($application, function (EntityManager $manager) use ($canonical): void {
                $this->object($manager)->isNotIdenticalTo($canonical);
                $this->object($manager->getConfiguration()->getQueryCache())->isNotIdenticalTo($canonical->getConfiguration()->getQueryCache());
            });
            Orm::withConnection($application, function (EntityManager $manager): void {
                $metadata = $manager->getClassMetadata(Config::class);
                $persister = $manager->getUnitOfWork()->getEntityPersister(Config::class);
                $automatic = $persister->getInsertSQL();
                $generatorType = $metadata->generatorType;
                $generator = $metadata->idGenerator;
                try {
                    $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
                    $metadata->setIdGenerator(new AssignedGenerator());
                    $this->string($persister->getInsertSQL())->isNotIdenticalTo($automatic)->contains('`id`');
                } finally {
                    $metadata->setIdGeneratorType($generatorType);
                    $metadata->setIdGenerator($generator);
                }
                $this->string($persister->getInsertSQL())->isIdenticalTo($automatic);
            });
            $originalString = DbalType::getType('string');
            $converter = new class () extends StringType {
                public bool $upper = true;

                public function convertToPHPValueSQL(string $expression, AbstractPlatform $platform): string
                {
                    return ($this->upper ? 'UPPER(' : 'LOWER(') . $expression . ')';
                }
            };
            $select = static fn (EntityManager $manager): string =>
                $manager->getUnitOfWork()->getEntityPersister(Config::class)->getSelectSQL(['id' => 1]);
            $plainSql = Orm::withConnection($application, $select);
            try {
                DbalType::getTypeRegistry()->override('string', $converter);
                $this->variable(Orm::withConnection($application, static fn (EntityManager $manager) => $manager->getConfiguration()->getQueryCache()))->isNull();
                $upperSql = Orm::withConnection($application, $select);
                $this->string($upperSql)->contains('UPPER(')->isNotIdenticalTo($plainSql);
                $converter->upper = false;
                $this->string(Orm::withConnection($application, $select))->contains('LOWER(')->isNotIdenticalTo($upperSql);
            } finally {
                DbalType::getTypeRegistry()->override('string', $originalString);
            }
            $this->string(Orm::withConnection($application, $select))->isIdenticalTo($plainSql);
            $originalFixed = DbalType::getType(FixedStringType::NAME);
            $fixedSelect = static fn (EntityManager $manager): string =>
                $manager->getUnitOfWork()->getEntityPersister(User::class)->getSelectSQL(['id' => 1]);
            $fixedSql = Orm::withConnection($application, $fixedSelect);
            $this->string($fixedSql)->contains('RTRIM(');
            try {
                DbalType::getTypeRegistry()->override('string', new FixedStringType());
                $this->string(Orm::withConnection($application, $select))->contains('RTRIM(')->isNotIdenticalTo($plainSql);
                DbalType::getTypeRegistry()->override('string', $originalString);
                $this->string(Orm::withConnection($application, $select))->isIdenticalTo($plainSql);
                DbalType::getTypeRegistry()->override(FixedStringType::NAME, new StringType());
                $this->string(Orm::withConnection($application, $fixedSelect))->notContains('RTRIM(')->isNotIdenticalTo($fixedSql);
            } finally {
                DbalType::getTypeRegistry()->override('string', $originalString);
                DbalType::getTypeRegistry()->override(FixedStringType::NAME, $originalFixed);
            }
            $this->string(Orm::withConnection($application, $fixedSelect))->isIdenticalTo($fixedSql);

            $priorManager = null;
            Orm::withConnection($application, static function (EntityManager $manager) use (&$priorManager): void {
                $priorManager = $manager;
            });
            try {
                // Even a same-class replacement changes the authoritative type binding.
                DbalType::getTypeRegistry()->override('string', new StringType());
                Orm::withConnection($application, function (EntityManager $manager) use ($priorManager): void {
                    $this->object($manager)->isNotIdenticalTo($priorManager);
                    $this->object($manager->getConfiguration()->getQueryCache())->isNotIdenticalTo($priorManager->getConfiguration()->getQueryCache());
                });
            } finally {
                DbalType::getTypeRegistry()->override('string', $originalString);
            }
            $rejected = null;
            $this->exception(function () use ($application, &$rejected): void {
                Orm::withConnection($application, static function (EntityManager $manager) use (&$rejected): void {
                    $rejected = $manager;
                    throw new LogicException('Rejected application operation');
                });
            })->isInstanceOf(LogicException::class);
            Orm::withConnection($application, function (EntityManager $manager) use ($rejected): void {
                $this->object($manager)->isNotIdenticalTo($rejected);
                $this->object($manager->getConfiguration()->getQueryCache())->isNotIdenticalTo($rejected->getConfiguration()->getQueryCache());
            });
            $failedCleanup = null;
            $this->exception(function () use ($application, &$failedCleanup): void {
                Orm::withConnection($application, static function (EntityManager $manager) use (&$failedCleanup): void {
                    $failedCleanup = $manager;
                    $manager->getEventManager()->addEventListener(Events::onClear, new class () {
                        public function onClear(): void
                        {
                            throw new LogicException('Rejected application cleanup');
                        }
                    });
                });
            })->isInstanceOf(LogicException::class);
            Orm::withConnection($application, function (EntityManager $manager) use ($failedCleanup): void {
                $this->object($manager)->isNotIdenticalTo($failedCleanup);
                $this->object($manager->getConfiguration()->getQueryCache())->isNotIdenticalTo($failedCleanup->getConfiguration()->getQueryCache());
                $this->integer($manager->getUnitOfWork()->size())->isIdenticalTo(0);
            });
            $this->boolean($application->isConnected())->isFalse();
            $this->boolean($otherRoute->isConnected())->isFalse();
        } finally {
            $application->close();
            $otherRoute->close();
        }

    }

    public function testPrivateQueryCacheReusesParsingWithFreshValuesAndLimits(): void
    {
        $application = DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'serverVersion' => '8.4.0', 'wrapperClass' => MySQLManagedConnection::class,
        ]);
        $parses = 0;
        $cache = null;
        try {
            Orm::withConnection($application, static function (EntityManager $manager) use (&$parses, &$cache): void {
                $cache = $manager->getConfiguration()->getQueryCache();
                // A stable function factory observes actual ORM parser work, not Query::parse entry counts.
                $manager->getConfiguration()->addCustomNumericFunction('CACHE_PROBE', static function (string $name) use (&$parses): BitCount {
                    ++$parses;
                    return new BitCount($name);
                });
            });
            foreach (['first', 'second'] as $index => $value) {
                Orm::withConnection($application, function (EntityManager $manager) use ($index, $value, $cache): void {
                    $this->object($manager->getConfiguration()->getQueryCache())->isIdenticalTo($cache);
                    $query = $manager->createQuery('SELECT CACHE_PROBE(c.id) AS bits FROM ' . Config::class . ' c WHERE c.name = :name')
                        ->setParameter('name', $value, 'string')->setMaxResults($index + 1);
                    $this->string($query->getSQL())->contains('BIT_COUNT(')->contains('LIMIT ' . ($index + 1));
                    $this->string($query->getParameter('name')->getValue())->isIdenticalTo($value);
                    $this->variable($manager->getConfiguration()->getResultCache())->isNull();
                });
                $this->integer($parses)->isIdenticalTo(1);
            }
            // DQL parameter types participate in the parser cache key.
            Orm::withConnection($application, function (EntityManager $manager): void {
                $query = $manager->createQuery('SELECT CACHE_PROBE(c.id) AS bits FROM ' . Config::class . ' c WHERE c.name = :name')
                    ->setParameter('name', 17, 'integer');
                $this->string($query->getSQL())->contains('BIT_COUNT(');
                $this->integer($query->getParameter('name')->getValue())->isIdenticalTo(17);
            });
            $this->integer($parses)->isIdenticalTo(2);
            $this->boolean($application->isConnected())->isFalse();
        } finally {
            $application->close();
        }
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
                    if ($metadata->name === ComputerVirtualMachine::class) {
                        $vm = EntityRegistry::virtualMachineCountMapping();
                        $this->array($vm);
                        $this->string($quoteName($vm['table']))->isIdenticalTo($quote->getTableName($metadata, $platform));
                        $this->string($quoteName($vm['id']))->isIdenticalTo($quote->getColumnName('id', $metadata, $platform));
                        $this->string($quoteName($vm['deleted']))->isIdenticalTo($quote->getColumnName('is_deleted', $metadata, $platform));
                        $this->string($vm['deleted'][2])->isIdenticalTo($metadata->getTypeOfField('is_deleted'));
                        $join = $metadata->associationMappings['computers']->joinColumns[0];
                        $this->string($quoteName($vm['host']))->isIdenticalTo($quote->getJoinColumnName($join, $metadata, $platform));
                    }
                    if ($metadata->name === Infocom::class) {
                        $presence = EntityRegistry::infocomPresenceMapping();
                        $this->array($presence);
                        $this->string($quoteName($presence['table']))->isIdenticalTo($quote->getTableName($metadata, $platform));
                        foreach (['id', 'itemtype', 'items_id'] as $field) {
                            $this->string($quoteName($presence[$field]))->isIdenticalTo($quote->getColumnName($field, $metadata, $platform));
                            $this->string($presence[$field][2])->isIdenticalTo($metadata->getTypeOfField($field));
                        }
                    }
                    if ($metadata->name === ITILFollowup::class) {
                        $promotion = EntityRegistry::promotionSourceMapping();
                        $this->array($promotion);
                        $this->string($quoteName($promotion['table']))->isIdenticalTo($quote->getTableName($metadata, $platform));
                        foreach (['id', 'itemtype'] as $property) {
                            $this->string($quoteName($promotion['fields'][$property]))->isIdenticalTo($quote->getColumnName($property, $metadata, $platform));
                            $this->string($promotion['fields'][$property][2])->isIdenticalTo($metadata->getTypeOfField($property));
                        }
                        foreach (['ticket', 'promotedTicket'] as $property) {
                            $join = $metadata->associationMappings[$property]->joinColumns[0];
                            $this->string($quoteName($promotion['references'][$property]))->isIdenticalTo($quote->getJoinColumnName($join, $metadata, $platform));
                        }
                    }
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
                $link = $manager->getClassMetadata(ComputerItem::class);
                $projection = EntityRegistry::computerItemMapping();
                $this->array($projection);
                $this->string($quoteName($projection['table']))->isIdenticalTo($quote->getTableName($link, $platform));
                foreach (['id', 'itemtype', 'items_id'] as $property) {
                    $this->string($quoteName($projection['fields'][$property]))->isIdenticalTo($quote->getColumnName($property, $link, $platform));
                    $this->string($projection['fields'][$property][2])->isIdenticalTo($link->getTypeOfField($property));
                }
                $join = $link->associationMappings['computers']->joinColumns[0];
                $this->string($quoteName($projection['computer']))->isIdenticalTo($quote->getJoinColumnName($join, $link, $platform));
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
        $first = (new RegistryCache($cache, MappingFingerprint::forRelease(__DIR__, '2.2.0')))->load($build);
        $first['types']['name'] = 'changed by caller';
        $second = (new RegistryCache($cache, MappingFingerprint::forRelease(__DIR__, '2.2.0')))->load($build);
        $this->integer($builds)->isIdenticalTo(1);
        $this->string($second['types']['name'])->isIdenticalTo('string');
        $this->object($second['reference'])->isNotIdenticalTo($first['reference']);
        $this->variable($second['reference']->policy->kind)->isIdenticalTo(ReferenceKind::RootEntity);
        $cache->clear();
        (new RegistryCache($cache, MappingFingerprint::forRelease(__DIR__, '2.2.0')))->load($build);
        $this->integer($builds)->isIdenticalTo(2);
    }

    public function testReleasesAndInstallationPathsHaveIndependentMappingCaches(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $builds = 0;
        $build = static function () use (&$builds): array {
            return ['generation' => ++$builds];
        };
        // The configured backend can have an explicit, unversioned namespace.
        $first = new RegistryCache($cache, MappingFingerprint::forRelease(__DIR__, '2.2.0'));
        $next = new RegistryCache($cache, MappingFingerprint::forRelease(__DIR__, '2.2.1'));
        $other = new RegistryCache($cache, MappingFingerprint::forRelease(__DIR__ . '/other', '2.2.0'));
        $this->array($first->load($build))->isIdenticalTo(['generation' => 1]);
        $this->array($next->load($build))->isIdenticalTo(['generation' => 2]);
        $this->array($other->load($build))->isIdenticalTo(['generation' => 3]);
        $this->array($first->load($build))->isIdenticalTo(['generation' => 1]);
        $this->integer($builds)->isIdenticalTo(3);
        $cache->clear();
        $this->array($first->load($build))->isIdenticalTo(['generation' => 4]);
        $this->array($next->load($build))->isIdenticalTo(['generation' => 5]);
        $this->array($other->load($build))->isIdenticalTo(['generation' => 6]);
    }

    public function testInstalledDependencyChangesUseIndependentMappingCaches(): void
    {
        $original = InstalledVersions::getRawData();
        $cache = new Psr16Cache(new ArrayAdapter());
        $builds = 0;
        $build = static function () use (&$builds): array {
            return ['generation' => ++$builds];
        };
        try {
            // Use Composer's supported reload on both sides of the comparison.
            InstalledVersions::reload($original);
            $first = new RegistryCache($cache, MappingFingerprint::forRelease(__DIR__, '2.2.0'));
            $this->array($first->load($build))->isIdenticalTo(['generation' => 1]);
            $changed = $original;
            $changed['versions']['doctrine/orm']['reference'] = 'different-installed-package-reference';
            InstalledVersions::reload($changed);
            $next = new RegistryCache($cache, MappingFingerprint::forRelease(__DIR__, '2.2.0'));
            $this->array($next->load($build))->isIdenticalTo(['generation' => 2]);
            $this->array($first->load($build))->isIdenticalTo(['generation' => 1]);
        } finally {
            InstalledVersions::reload($original);
        }
    }

    public function testDamagedCacheIsAMissAndDoesNotMaskMappingFailures(): void
    {
        $pool = new ArrayAdapter(storeSerialized: false);
        $cache = new Psr16Cache($pool);
        $builds = 0;
        $build = static function () use (&$builds): array {
            return ['generation' => ++$builds];
        };
        $registry = new RegistryCache($cache, MappingFingerprint::forRelease(__DIR__, '2.2.0'));
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
        $registry = new RegistryCache($cache, MappingFingerprint::forRelease(__DIR__, '2.2.0'));
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

    public function testDeploymentIdentityDoesNotRequireSourceFilesOrComposerManifests(): void
    {
        $this->string(MappingFingerprint::current())->isNotEmpty(); // Also works with vendor-only bootstrap.
        $fingerprint = MappingFingerprint::forRelease('/packaged-release-without-sources', '2.2.0');
        $cache = new Psr16Cache(new ArrayAdapter());
        $builds = 0;
        $build = static function () use (&$builds): array {
            return ['generation' => ++$builds];
        };
        (new RegistryCache($cache, $fingerprint))->load($build);
        (new RegistryCache($cache, $fingerprint))->load($build);
        $this->integer($builds)->isIdenticalTo(1);
    }

    public function testCacheFailuresUseAuthoritativeMapping(): void
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
        $this->array((new RegistryCache($cache, MappingFingerprint::forRelease(__DIR__, '2.2.0')))->load($build))->isIdenticalTo(['generation' => 1]);
        $this->array((new RegistryCache($cache, MappingFingerprint::forRelease(__DIR__, '2.2.0')))->load($build))->isIdenticalTo(['generation' => 2]);
    }

    public function testSerializedMetadataBridgePreservesFreshObjectsAndDeploymentNamespaces(): void
    {
        $memory = new ArrayAdapter(storeSerialized: false);
        $pool = new Psr16Cache($memory);
        $fingerprint = MappingFingerprint::forRelease(__DIR__, '2.2.0');
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
        $rotated = new SerializedMetadataCache($pool, 'metadata_' . MappingFingerprint::forRelease(__DIR__, '2.2.1'));
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
        $snapshot = static fn (): array => [
            EntityRegistry::tables(), EntityRegistry::legacyTables(), EntityRegistry::relations(),
            EntityRegistry::lifecycleRelations(), EntityRegistry::nativeTimestamps(), EntityRegistry::booleanColumns(),
            EntityRegistry::references('glpi_tickets'), EntityRegistry::fieldTypes('glpi_entities'), EntityRegistry::fieldEnums('glpi_entities'),
            EntityRegistry::promotionSourceMapping(), EntityRegistry::computerItemMapping(), EntityRegistry::virtualMachineCountMapping(),
            EntityRegistry::infocomPresenceMapping(), EntityRegistry::scalarIdentifiers(), EntityRegistry::reservationUserMapping(),
            EntityRegistry::entityScopeOwner('glpi_items_devicememories'), EntityRegistry::componentCountMapping('glpi_items_devicememories'),
            EntityRegistry::treePointMapping('glpi_entities'), EntityRegistry::booleanFields('glpi_entities'),
            EntityRegistry::columnNames('glpi_entities'), EntityRegistry::readOnlyColumns('glpi_items_devicememories'),
            EntityRegistry::discriminatedReferences('glpi_items_devicememories'), EntityRegistry::relationsByPolicy(ReferenceKind::Inherited),
        ];
        try {
            $model->setValue(null, null);
            $cold = serialize($snapshot());
            $viewCount = count($model->getValue());
            $this->array(EntityRegistry::tables())->isNotEmpty();
            $this->array($pool->getValues())->hasSize($viewCount, 'One cold build populates every logical view');
            foreach ($pool->getValues() as $value) {
                $this->string($value);
            }
            $model->setValue(null, null);
            EntityRegistry::tables();
            $this->array(array_keys($model->getValue()))->isIdenticalTo(['tables'], 'A warm table lookup loads only its logical view');
            $this->string(serialize($snapshot()))->isIdenticalTo($cold);
            $this->integer(count($model->getValue()))->isIdenticalTo($viewCount);
            // A valid old-format view has no enum facts and must be rebuilt.
            $bytes = serialize(['tables' => EntityRegistry::tables()]);
            $key = 'orm_registry_view_' . MappingFingerprint::current() . '_tables';
            $GLOBALS['GLPI_CACHE']->set($key, '1:' . hash('sha256', $bytes) . ':' . $bytes);
            $model->setValue(null, null);
            $this->string(serialize($snapshot()))->isIdenticalTo($cold);
            $this->string($GLOBALS['GLPI_CACHE']->get($key))->startWith('2:');
            $GLOBALS['GLPI_CACHE']->clear();
            $model->setValue(null, null);
            $this->string(serialize($snapshot()))->isIdenticalTo($cold);

            $unavailable = new class (new ArrayAdapter()) extends Psr16Cache {
                public int $gets = 0;
                public bool $throws = false;

                public function get($key, $default = null): mixed
                {
                    ++$this->gets;
                    return null;
                }

                public function setMultiple($values, $ttl = null): bool
                {
                    if ($this->throws) {
                        throw new RuntimeException('Cache unavailable');
                    }
                    return false;
                }
            };
            $GLOBALS['GLPI_CACHE'] = $unavailable;
            foreach ([false, true] as $throws) {
                $unavailable->throws = $throws;
                $unavailable->gets = 0;
                $model->setValue(null, null);
                $this->string(serialize($snapshot()))->isIdenticalTo($cold);
                $this->integer($unavailable->gets)->isIdenticalTo(1, 'All views remain local after a failed cache population');
            }
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
