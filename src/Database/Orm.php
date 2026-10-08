<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use itsmng\Database\Mapping\AttributeDriver;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class Orm
{
    private static int $unitsOfWork = 0;
    /**
     * A unit of work never outlives an application operation: legacy writers do
     * not notify Doctrine's identity map. The connection and transaction are shared.
     */
    public static function create(DBAdapter $db): EntityManager
    {
        $connection = $db->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($db, $connection);
        return self::forConnection($connection);
    }

    /** Construct on the operation's already selected route without resolving it again. */
    public static function forConnection(Connection $connection): EntityManager
    {
        return self::manager($connection, self::configuration($connection->getDatabasePlatform()));
    }

    private static function manager(Connection $connection, Configuration $configuration): EntityManager
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
    public static function readRecord(DBAdapter $db, string $table, string $column, int $id): ?array
    {
        $operation = new RecordReadOperation($db->getDoctrineConnection());
        try {
            return $operation->row($table, $column, $id);
        } finally {
            $operation->close();
        }
    }

    /** Independent mutable configuration; no caller can alter another operation. */
    public static function configuration(AbstractPlatform $platform): Configuration
    {
        $proxyDirectory = defined('GLPI_CACHE_DIR') ? GLPI_CACHE_DIR . '/orm' : sys_get_temp_dir() . '/itsm-orm';
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
        return $config;
    }
}
