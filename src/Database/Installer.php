<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;

/** Shared schema installation and provider safeguards. */
final class Installer
{
    public static function checkPostgres(\DBAdapter $database): void
    {
        if (!$database->connected) {
            throw new \RuntimeException($database->error());
        }
        if (version_compare($database->getVersion(), '14', '<')) {
            throw new \RuntimeException('PostgreSQL 14 or later is required.');
        }
        if (count($database->listTables()) > 0) {
            throw new \RuntimeException('PostgreSQL installation requires an empty schema. Use a new database.');
        }
    }

    public static function installPostgres(\DBAdapter $database, string $language): void
    {
        self::checkPostgres($database);
        $database->getDoctrineConnection()->transactional(static function () use ($database, $language): void {
            \Toolbox::createSchema($language, $database);
        });
    }

    /** MySQL DDL commits separately; build the full plan before replacing core tables. */
    public static function installMysqlSchema(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $baseline = new BaselineSchema();
        $schema = $baseline->build($platform, false);
        $sql = $baseline->toSql($platform, false);
        $existing = array_flip($connection->createSchemaManager()->listTableNames());
        $enabled = (int)$connection->fetchOne('SELECT @@FOREIGN_KEY_CHECKS');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            // A forced fresh install replaces the core schema and its adoption record.
            // An old completion/journal must never suppress the new seed conversion.
            if (isset($existing[Migration\LegacyToOrm::LEDGER])) {
                $connection->executeStatement($platform->getDropTableSQL(Migration\LegacyToOrm::LEDGER));
            }
            foreach ($schema->getTables() as $table) {
                if (isset($existing[$table->getName()])) {
                    $connection->executeStatement($platform->getDropTableSQL($table->getQuotedName($platform)));
                }
            }
            foreach ($sql as $statement) {
                $connection->executeStatement($statement);
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = ' . $enabled);
        }
    }
}
