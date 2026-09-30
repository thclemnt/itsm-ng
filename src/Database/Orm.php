<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Proxy\ProxyFactory;
use itsmng\Database\Mapping\AttributeDriver;

final class Orm
{
    /**
     * A unit of work never outlives an application operation: legacy writers do
     * not notify Doctrine's identity map. The connection and transaction are shared.
     */
    public static function create(\DBAdapter $db): EntityManager
    {
        if (!\Doctrine\DBAL\Types\Type::hasType(Type\ClockTimeType::NAME)) {
            \Doctrine\DBAL\Types\Type::addType(Type\ClockTimeType::NAME, Type\ClockTimeType::class);
        }
        $config = new Configuration();
        $config->addCustomStringFunction('REPLACE', Query\Replace::class);
        $config->addCustomStringFunction('YEAR_MONTH', Query\YearMonth::class);
        $config->addCustomNumericFunction('BIT_COUNT', Query\BitCount::class);
        $config->addCustomNumericFunction('EPOCH_SECONDS', Query\EpochSeconds::class);
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/Entity'], $db->getDoctrineConnection()->getDatabasePlatform()));
        $config->setProxyDir(GLPI_CACHE_DIR . '/orm');
        $config->setProxyNamespace('itsmng\\Database\\Proxy');
        $config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);
        return new EntityManager($db->getDoctrineConnection(), $config);
    }
}
