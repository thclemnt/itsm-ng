<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\V220\Baseline as FrozenBaseline;
use itsmng\Database\Migration\V220\NetworkPortAggregateOrigins;
use itsmng\Database\Migration\V220\PlanningEventGuests;
use RuntimeException;
use Toolbox;

/** Shared schema installation and provider safeguards. */
final class Installer
{
    public static function checkPostgres(DBAdapter $database): void
    {
        if (!$database->connected) {
            throw new RuntimeException($database->error());
        }
        if (version_compare($database->getVersion(), '14', '<')) {
            throw new RuntimeException('PostgreSQL 14 or later is required.');
        }
        if (count($database->listTables()) > 0 && !History::isInstalling($database->getDoctrineConnection())) {
            throw new RuntimeException('PostgreSQL installation requires an empty schema. Use a new database.');
        }
    }

    public static function installPostgres(DBAdapter $database, string $language): void
    {
        self::checkPostgres($database);
        $database->getDoctrineConnection()->transactional(static function () use ($database, $language): void {
            Toolbox::createSchema($language, $database);
        });
    }

    /** Retained schema-only compatibility API; complete installs use History::install(). */
    public static function installMysqlSchema(Connection $connection): void
    {
        if (!History::isInstalling($connection)) {
            self::resetMysqlCore($connection);
        }
        (new History())->baseline($connection);
    }

    /** Only explicit fresh replacement invokes this; unfinished journals resume instead. */
    public static function resetMysqlCore(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $schema = (new FrozenBaseline())->build($platform);
        $names = [
            ...array_map(static fn ($table) => $table->getName(), $schema->getTables()),
            NetworkPortAggregateOrigins::TABLE,
            PlanningEventGuests::TABLE,
        ];
        $manager = $connection->createSchemaManager();
        $existing = array_flip($manager->listTableNames());
        foreach (array_diff(array_keys($existing), $names, [Ledger::TABLE]) as $name) {
            foreach ($manager->listTableForeignKeys($name) as $key) {
                if (in_array($key->getForeignTableName(), $names, true)) {
                    throw new RuntimeException('Cannot replace core schema referenced by custom table: ' . $name . '. Use the validated upgrade path.');
                }
            }
        }
        $enabled = (int)$connection->fetchOne('SELECT @@FOREIGN_KEY_CHECKS');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            // A forced fresh install replaces the core schema and its adoption record.
            // An old completion/journal must never suppress the new seed conversion.
            $drop = array_filter([Ledger::TABLE, ...$names], static fn ($name) => isset($existing[$name]));
            if ($drop !== []) {
                $connection->executeStatement('DROP TABLE ' . implode(', ', array_map($platform->quoteIdentifier(...), $drop)));
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = ' . $enabled);
        }
    }
}
