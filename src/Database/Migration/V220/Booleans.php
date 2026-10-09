<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Migration\Ledger;
use RuntimeException;

/** Adopt early PostgreSQL integer flags using the frozen baseline's flag semantics. */
final class Booleans
{
    public const PHASE = '20261002_postgres_boolean_flags';

    public function plan(Connection $connection): array
    {
        if ((Ledger::state($connection, self::PHASE)['complete'] ?? false) === true) {
            return [];
        }
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return []; // Retain native MySQL/MariaDB flag storage.
        }
        $manager = $connection->createSchemaManager();
        $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
        $sql = [];
        foreach ((new Baseline())->build($connection->getDatabasePlatform())->getTables() as $table) {
            $flags = array_filter($table->getColumns(), static fn ($column) => Type::lookupName($column->getType()) === Types::BOOLEAN);
            if (!$flags || !$manager->tablesExist([$table->getName()])) {
                continue;
            }
            $actual = $manager->listTableColumns($table->getName());
            foreach ($flags as $column) {
                $name = $column->getName();
                $installed = $actual[strtolower($name)] ?? null;
                if ($installed === null || Type::lookupName($installed->getType()) === Types::BOOLEAN) {
                    continue;
                }
                $type = Type::lookupName($installed->getType());
                if (!in_array($type, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
                    throw new RuntimeException('Unsupported legacy boolean type: ' . $table->getName() . '.' . $name . ' (' . $type . ')');
                }
                $field = $quote($name);
                $relation = $quote($table->getName());
                $invalid = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $relation . ' WHERE ' . $field . ' IS NOT NULL AND ' . $field . ' NOT IN (0, 1)');
                if ($invalid) {
                    $id = $table->hasColumn('id') ? $quote('id') : $field;
                    $sample = $connection->fetchAllAssociative('SELECT ' . $id . ', ' . $field . ' FROM ' . $relation . ' WHERE ' . $field . ' NOT IN (0, 1) LIMIT 5');
                    throw new RuntimeException('Invalid legacy boolean: ' . $table->getName() . '.' . $name . ' (' . $invalid . ' rows); samples: ' . json_encode($sample, JSON_THROW_ON_ERROR));
                }
                $default = $installed->getDefault();
                if (!in_array($default, [null, 0, 1, '0', '1', false, true], true)) {
                    throw new RuntimeException('Invalid legacy boolean default: ' . $table->getName() . '.' . $name);
                }
                $sql[] = 'ALTER TABLE ' . $relation . ' ALTER COLUMN ' . $field . ' DROP DEFAULT, ALTER COLUMN ' . $field
                    . ' TYPE BOOLEAN USING (' . $field . ' = 1)'
                    . ($default === null ? '' : ', ALTER COLUMN ' . $field . ' SET DEFAULT ' . ((bool)(int)$default ? 'TRUE' : 'FALSE'));
            }
        }
        return $sql;
    }

    public function apply(Connection $connection): void
    {
        if ((Ledger::state($connection, self::PHASE)['complete'] ?? false) === true) {
            return;
        }
        $apply = function () use ($connection): void {
            foreach ($this->plan($connection) as $sql) {
                $connection->executeStatement($sql);
            }
            Ledger::save($connection, self::PHASE, ['complete' => true]);
        };
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $connection->transactional($apply);
        } else {
            $apply();
        }
    }
}
