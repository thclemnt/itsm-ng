<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Table;

/** Shared storage for canonical migrations and the existing adoption DDL journal. */
final class Ledger
{
    /** Establish existence and refuse receipts that cannot share the core transaction. */
    public static function assertTransactional(Connection $connection): bool
    {
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $engine = $connection->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [LegacyToOrm::LEDGER]);
            if ($engine === false) {
                return false;
            }
            if (strcasecmp((string)$engine, 'InnoDB') !== 0) {
                throw new \RuntimeException('The migration ledger ' . LegacyToOrm::LEDGER . ' must use InnoDB; found ' . ($engine ?? 'no transactional table engine')
                    . '. Stop application writers and reconcile the ledger against the actual schema and imported data before converting its engine. Existing completion receipts may have survived rolled-back work and cannot be trusted or automatically repaired.');
            }
            return true;
        }
        return $connection->fetchOne('SELECT to_regclass(?)', [LegacyToOrm::LEDGER]) !== null;
    }

    public static function state(Connection $connection, string $version): ?array
    {
        if (!self::assertTransactional($connection)) {
            return null;
        }
        $value = $connection->fetchOne('SELECT state FROM ' . LegacyToOrm::LEDGER . ' WHERE version = ?', [$version]);
        return $value === false ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function save(Connection $connection, string $version, array $state): void
    {
        if (!self::assertTransactional($connection)) {
            if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform && $connection->isTransactionActive()) {
                throw new \RuntimeException('Create the migration ledger outside an application transaction before recording work; MySQL CREATE TABLE would commit unrelated changes implicitly.');
            }
            $table = new Table(LegacyToOrm::LEDGER);
            $table->addColumn('version', 'string', ['length' => 100]);
            $table->addColumn('state', 'text', ['length' => 4294967295]);
            $table->setPrimaryKey(['version']);
            if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $table->addOption('engine', 'InnoDB');
            }
            $connection->createSchemaManager()->createTable($table);
        }
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        if ($connection->fetchOne('SELECT 1 FROM ' . LegacyToOrm::LEDGER . ' WHERE version = ?', [$version]) === false) {
            $connection->insert(LegacyToOrm::LEDGER, ['version' => $version, 'state' => $encoded]);
        } else {
            $connection->update(LegacyToOrm::LEDGER, ['state' => $encoded], ['version' => $version]);
        }
    }
}
