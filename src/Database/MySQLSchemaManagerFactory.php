<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\SchemaManagerFactory;

/** One public DBAL factory preserves the native managers outside MariaDB. */
final class MySQLSchemaManagerFactory implements SchemaManagerFactory
{
    public function createSchemaManager(Connection $connection): AbstractSchemaManager
    {
        $platform = $connection->getDatabasePlatform();
        return $platform instanceof MariaDBPlatform
            ? new MariaDBSchemaManager($connection, $platform)
            : $platform->createSchemaManager($connection);
    }
}
