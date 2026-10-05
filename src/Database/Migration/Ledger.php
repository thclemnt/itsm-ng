<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Table;

/** Shared storage for canonical migrations and the existing adoption DDL journal. */
final class Ledger
{
    public const TABLE = 'itsmng_migrations';

    /** Establish existence and refuse receipts that cannot share the core transaction. */
    public static function assertTransactional(Connection $connection): bool
    {
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $engine = $connection->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [self::TABLE]);
            if ($engine === false) {
                return false;
            }
            if (strcasecmp((string)$engine, 'InnoDB') !== 0) {
                throw new \RuntimeException('The migration ledger ' . self::TABLE . ' must use InnoDB; found ' . ($engine ?? 'no transactional table engine')
                    . '. Stop application writers and reconcile the ledger against the actual schema and imported data before converting its engine. Existing completion receipts may have survived rolled-back work and cannot be trusted or automatically repaired.');
            }
            return true;
        }
        return $connection->fetchOne('SELECT to_regclass(?)', [self::TABLE]) !== null;
    }

    /** Read the canonical ledger once without creating it or journaling progress. */
    public static function states(Connection $connection): array
    {
        if (!self::assertTransactional($connection)) {
            return [];
        }
        $states = [];
        foreach ($connection->fetchAllAssociative('SELECT version, state FROM ' . self::TABLE) as $row) {
            $states[$row['version']] = json_decode($row['state'], true, flags: JSON_THROW_ON_ERROR);
        }
        return $states;
    }

    public static function state(Connection $connection, string $version): ?array
    {
        if (!self::assertTransactional($connection)) {
            return null;
        }
        $value = $connection->fetchOne('SELECT state FROM ' . self::TABLE . ' WHERE version = ?', [$version]);
        return $value === false ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    /** Bootstrap storage without claiming any data operation has completed. */
    public static function ensure(Connection $connection): void
    {
        if (!self::assertTransactional($connection)) {
            if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform && $connection->isTransactionActive()) {
                throw new \RuntimeException('Create the migration ledger outside an application transaction before recording work; MySQL CREATE TABLE would commit unrelated changes implicitly.');
            }
            $table = new Table(self::TABLE);
            $table->addColumn('version', 'string', ['length' => 100]);
            $table->addColumn('state', 'text', ['length' => 4294967295]);
            $table->setPrimaryKey(['version']);
            if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $table->addOption('engine', 'InnoDB');
            }
            $connection->createSchemaManager()->createTable($table);
        }
    }

    public static function save(Connection $connection, string $version, array $state): void
    {
        self::ensure($connection);
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        if ($connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE version = ?', [$version]) === false) {
            $connection->insert(self::TABLE, ['version' => $version, 'state' => $encoded]);
        } else {
            $connection->update(self::TABLE, ['state' => $encoded], ['version' => $version]);
        }
    }
}
