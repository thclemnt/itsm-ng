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
    /**
     * A unit of work never outlives an application operation: legacy writers do
     * not notify Doctrine's identity map. The connection and transaction are shared.
     */
    public static function create(\DBAdapter $db): EntityManager
    {
        // A metadata factory and its unit of work refer back to their manager.
        // After bulk mapping inspection PHP raises its automatic GC threshold;
        // discarded managers can then accumulate faster than it collects them.
        // Collect in bounded batches without sharing or clearing live managers.
        if (++self::$unitsOfWork % 8 === 0) {
            gc_collect_cycles();
        }
        return new EntityManager($db->getDoctrineConnection(), self::configuration($db->getDoctrineConnection()->getDatabasePlatform()));
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
        $config->addCustomStringFunction('REPLACE', Query\Replace::class);
        $config->addCustomStringFunction('YEAR_MONTH', Query\YearMonth::class);
        $config->addCustomStringFunction('TEMPORAL_TEXT', Query\TemporalText::class);
        $config->addCustomNumericFunction('BIT_COUNT', Query\BitCount::class);
        $config->addCustomNumericFunction('EPOCH_SECONDS', Query\EpochSeconds::class);
        $config->addCustomNumericFunction('CURRENT_EPOCH_SECONDS', Query\CurrentEpochSeconds::class);
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
