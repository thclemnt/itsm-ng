<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;

/** Shared storage for canonical migrations and the existing adoption DDL journal. */
final class Ledger
{
    public static function state(Connection $connection, string $version): ?array
    {
        if (!$connection->createSchemaManager()->tablesExist([LegacyToOrm::LEDGER])) {
            return null;
        }
        $value = $connection->fetchOne('SELECT state FROM ' . LegacyToOrm::LEDGER . ' WHERE version = ?', [$version]);
        return $value === false ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function save(Connection $connection, string $version, array $state): void
    {
        if (!$connection->createSchemaManager()->tablesExist([LegacyToOrm::LEDGER])) {
            $table = new Table(LegacyToOrm::LEDGER);
            $table->addColumn('version', 'string', ['length' => 100]);
            $table->addColumn('state', 'text', ['length' => 4294967295]);
            $table->setPrimaryKey(['version']);
            $connection->createSchemaManager()->createTable($table);
        }
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        if (self::state($connection, $version) === null) {
            $connection->insert(LegacyToOrm::LEDGER, ['version' => $version, 'state' => $encoded]);
        } else {
            $connection->update(LegacyToOrm::LEDGER, ['state' => $encoded], ['version' => $version]);
        }
    }
}
