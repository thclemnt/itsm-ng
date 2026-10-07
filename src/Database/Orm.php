<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use itsmng\Database\Mapping\AttributeDriver;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class Orm
{
    /** Mapping configuration and serialized metadata are shared, managed records are not. */
    private static array $configurations = [];
    private static int $unitsOfWork = 0;
    private static ?\WeakMap $queryCaches = null;
    /**
     * A unit of work never outlives an application operation: legacy writers do
     * not notify Doctrine's identity map. The connection and transaction are shared.
     */
    public static function create(\DBAdapter $db): EntityManager
    {
        $connection = $db->getDoctrineConnection();
        $configuration = self::configuration($connection->getDatabasePlatform());
        // Cache compiled DQL, never rows or managed entities. Only this owned
        // configuration participates: public configurations may replace mapping
        // drivers/listeners and must not inherit a different mapping's SQL.
        self::$queryCaches ??= new \WeakMap();
        $driver = $configuration->getMetadataDriverImpl();
        // Bootstrap configuration reads precede GLPI_CACHE. Resolve this on every
        // owned operation so the first uncached manager cannot disable later hits.
        // Public configuration() and custom mapping/listener callers stay isolated.
        $pool = $GLOBALS['GLPI_CACHE'] ?? null;
        if ($pool instanceof \Psr\SimpleCache\CacheInterface && ($fingerprint = MappingFingerprint::current()) !== null) {
            $context = hash('sha256', $fingerprint . "\0" . $connection->getDatabasePlatform()::class
                . "\0" . $driver::class . "\0" . $configuration->getProxyDir());
            $configuration->setMetadataCache(new SerializedMetadataCache($pool, 'orm_metadata_' . $context));
        }
        $configuration->setQueryCache(self::$queryCaches[$driver] ??= new ArrayAdapter(storeSerialized: true));
        return self::manager($connection, $configuration);
    }

    private static function manager(\Doctrine\DBAL\Connection $connection, Configuration $configuration): EntityManager
    {
        // A metadata factory and its unit of work refer back to their manager.
        // After bulk mapping inspection PHP raises its automatic GC threshold;
        // discarded managers can then accumulate faster than it collects them.
        // Collect in bounded batches without sharing or clearing live managers.
        if (++self::$unitsOfWork % 8 === 0) {
            gc_collect_cycles();
        }
        return new EntityManager($connection, $configuration);
    }

    /** One current model-row operation; only its scalar branch owns a private compiled plan. */
    public static function readRecord(\DBAdapter $db, string $table, string $column, int $id): ?array
    {
        $connection = $db->getDoctrineConnection();
        $configuration = self::configuration($connection->getDatabasePlatform());
        // Public clones may mutate their shared driver/cache. Neither enters this read.
        $configuration->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/Entity'], $connection->getDatabasePlatform()));
        $configuration->setMetadataCache(new ArrayAdapter(storeSerialized: true));
        $cache = null;
        // An extension platform can derive SQL from state outside our deployment key.
        $platformFile = (new \ReflectionClass($connection->getDatabasePlatform()))->getFileName();
        $dbalPath = \Composer\InstalledVersions::getInstallPath('doctrine/dbal');
        $standardPlatform = $platformFile !== false && $dbalPath !== null
            && ($platformFile = realpath($platformFile)) !== false
            && ($dbalPath = realpath($dbalPath)) !== false
            && str_starts_with($platformFile, $dbalPath . '/src/Platforms/');
        $pool = $standardPlatform ? ($GLOBALS['GLPI_CACHE'] ?? null) : null;
        if ($pool instanceof \Psr\SimpleCache\CacheInterface && ($fingerprint = MappingFingerprint::current()) !== null) {
            $driver = $configuration->getMetadataDriverImpl();
            $context = hash('sha256', $fingerprint . "\0" . $connection->getDatabasePlatform()::class
                . "\0" . $driver::class . "\0" . $configuration->getProxyDir());
            $configuration->setMetadataCache(new SerializedMetadataCache($pool, 'orm_record_metadata_' . $context));
            $cache = new SerializedMetadataCache($pool, 'orm_record_query_' . $context);
        }
        $manager = self::manager($connection, $configuration);
        try {
            $metadata = $manager->getClassMetadata(EntityRegistry::tables()[$table]);
            $records = new Repository\RecordRepository($manager);
            if (count($metadata->identifier) === 1
                && $metadata->hasField($metadata->getSingleIdentifierFieldName())
                && $metadata->getColumnName($metadata->getSingleIdentifierFieldName()) === $column
                && !$metadata->hasLifecycleCallbacks(\Doctrine\ORM\Events::postLoad)
                && empty($metadata->entityListeners[\Doctrine\ORM\Events::postLoad])
                && !$manager->getEventManager()->hasListeners(\Doctrine\ORM\Events::postLoad)) {
                if ($cache !== null) {
                    // SQL conversion belongs to the current global type registry.
                    // Custom PHP value conversion remains fresh during hydration.
                    foreach (array_unique(array_column($metadata->fieldMappings, 'type')) as $name) {
                        $type = \Doctrine\DBAL\Types\Type::getType($name);
                        if ($name === Type\FixedStringType::NAME && $type::class === Type\FixedStringType::class) {
                            continue; // Final core implementation covered by MappingFingerprint.
                        }
                        foreach (['convertToPHPValueSQL', 'convertToDatabaseValueSQL'] as $method) {
                            if ((new \ReflectionMethod($type, $method))->getDeclaringClass()->getName() !== \Doctrine\DBAL\Types\Type::class) {
                                $cache = null;
                                break 2;
                            }
                        }
                    }
                }
                $defaultIdentifiers = $standardPlatform ? EntityRegistry::scalarIdentifiers() : [];
                $identifier = $metadata->getSingleIdentifierFieldName();
                $canonical = ($defaultIdentifiers[$metadata->name] ?? null) === [
                    'property' => $identifier,
                    'column' => $metadata->getColumnName($identifier),
                    'type' => $metadata->getTypeOfField($identifier),
                ];
                return $records->scalarRow(
                    $metadata->name,
                    $id,
                    $cache,
                    $canonical ? $defaultIdentifiers : null,
                );
            }
            // Entity callbacks may observe the manager. Their later metadata loads
            // and custom queries must not write either private persistent cache.
            $local = new ArrayAdapter(storeSerialized: true);
            $configuration->setMetadataCache($local);
            $manager->getMetadataFactory()->setCache($local);
            return $records->find($table, $column, $id);
        } finally {
            $manager->clear();
        }
    }

    public static function configuration(AbstractPlatform $platform): Configuration
    {
        $proxyDirectory = defined('GLPI_CACHE_DIR') ? GLPI_CACHE_DIR . '/orm' : sys_get_temp_dir() . '/itsm-orm';
        $key = $platform::class . ':' . $proxyDirectory;
        if (isset(self::$configurations[$key])) {
            return clone self::$configurations[$key];
        }
        if (!\Doctrine\DBAL\Types\Type::hasType(Type\ClockTimeType::NAME)) {
            \Doctrine\DBAL\Types\Type::addType(Type\ClockTimeType::NAME, Type\ClockTimeType::class);
        }
        if (!\Doctrine\DBAL\Types\Type::hasType(Type\FixedStringType::NAME)) {
            \Doctrine\DBAL\Types\Type::addType(Type\FixedStringType::NAME, Type\FixedStringType::class);
        }
        $config = new Configuration();
        $config->setDefaultRepositoryClassName(Repository\DropdownChoiceRepository::class);
        $config->addCustomStringFunction('REPLACE', Query\Replace::class);
        $config->addCustomStringFunction('YEAR_MONTH', Query\YearMonth::class);
        $config->addCustomStringFunction('TEMPORAL_TEXT', Query\TemporalText::class);
        $config->addCustomNumericFunction('BIT_COUNT', Query\BitCount::class);
        $config->addCustomNumericFunction('EPOCH_SECONDS', Query\EpochSeconds::class);
        $config->addCustomNumericFunction('CURRENT_EPOCH_SECONDS', Query\CurrentEpochSeconds::class);
        $config->addCustomNumericFunction('AUTO_NAME_NUMBER', Query\AutoNameNumber::class);
        $config->addCustomNumericFunction('KB_MATCH', Query\KnowledgeBaseFullText::class);
        $config->addCustomNumericFunction('KB_SCORE', Query\KnowledgeBaseFullText::class);
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/Entity'], $platform));
        // Serialization keeps mutable metadata (e.g. assigned-ID imports) local
        // to each metadata factory, rather than leaking changes between units of work.
        $config->setMetadataCache(new ArrayAdapter(storeSerialized: true));
        $config->setProxyDir($proxyDirectory);
        $config->setProxyNamespace('itsmng\\Database\\Proxy');
        $config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);
        self::$configurations[$key] = $config;
        return clone $config;
    }
}
