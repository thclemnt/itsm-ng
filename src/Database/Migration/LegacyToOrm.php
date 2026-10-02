<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\ForeignKeys;

/** One adoption migration for the frozen legacy baseline and partially upgraded ORM installations. */
final class LegacyToOrm
{
    public const VERSION = '20261001_legacy_to_orm_bigint';
    public const LEDGER = 'itsmng_migrations';

    private static function stages(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/history/20261001-stages.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function state(Connection $connection): ?array
    {
        return Ledger::state($connection, self::VERSION);
    }

    private function auditRequiredReferences(Connection $connection): void
    {
        $handled = [];
        foreach (['optional', 'audience', 'global', 'inherited'] as $section) {
            foreach (ReferenceHistory::get($section) as $table => $relations) {
                foreach ($relations as $column => $_) {
                    $handled[$table][$column] = true;
                }
            }
        }
        $handled['glpi_entities']['entities_id'] = true;
        $handled['glpi_slms']['calendars_id'] = true;
        $manager = $connection->createSchemaManager();
        $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
        foreach (IdentifierColumns::history()['relations'] as $table => $relations) {
            if (!$manager->tablesExist([$table])) {
                continue;
            }
            $columns = $manager->listTableColumns($table);
            foreach ($relations as $column => $target) {
                if (isset($handled[$table][$column]) || !isset($columns[strtolower($column)])) {
                    continue; // Domain helpers audit sentinel conversions and future typed columns.
                }
                $count = $connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($table) . ' c LEFT JOIN ' . $quote($target) . ' p ON c.' . $quote($column) . ' = p.id WHERE c.' . $quote($column) . ' IS NOT NULL AND p.id IS NULL');
                if ($count) {
                    throw new \RuntimeException('Orphaned required reference: ' . $table . '.' . $column . ' (' . $count . ')');
                }
            }
        }
    }

    public function plan(Connection $connection): array
    {
        $state = $this->state($connection);
        if (($state['complete'] ?? false) === true) {
            return ['complete' => true, 'identifiers' => [], 'stages' => []];
        }
        if ($state !== null) {
            return ['complete' => false, 'identifiers' => array_slice($state['identifiers'], $state['next']), 'stages' => self::stages()];
        }
        $this->auditRequiredReferences($connection);
        $identifiers = (new WideIdentifiers())->plan($connection);
        $stages = [];
        // Audit all supported conversions before starting nontransactional MySQL DDL.
        foreach (self::stages() as $name) {
            $class = __NAMESPACE__ . '\\' . $name;
            $stages[$name] = (new $class())->plan($connection);
        }
        return ['complete' => false, 'identifiers' => $identifiers, 'stages' => $stages];
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('The ORM schema requires 64-bit PHP integers.');
        }
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (!$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('Run the legacy-to-ORM migration outside an application transaction; MySQL DDL commits implicitly.');
        }
        $apply = function () use ($connection, $progress): void {
            $state = $this->state($connection);
            if (($state['complete'] ?? false) === true) {
                return;
            }
            if ($state === null) {
                $plan = $this->plan($connection);
                $state = ['complete' => false, 'identifiers' => $plan['identifiers'], 'next' => 0];
                Ledger::save($connection, self::VERSION, $state);
            }
            $save = static function () use ($connection, &$state): void {
                Ledger::save($connection, self::VERSION, $state);
            };
            $progress && $progress('Widening identifiers and preserving existing constraints');
            while ($state['next'] < count($state['identifiers'])) {
                WideIdentifiers::execute($connection, $state['identifiers'][$state['next']]);
                // Persist the next operation after every successful DDL statement.
                $state['next']++;
                $save();
            }
            foreach (self::stages() as $name) {
                $progress && $progress($name);
                $class = __NAMESPACE__ . '\\' . $name;
                (new $class())->apply($connection);
            }
            $progress && $progress('Installing audited foreign keys');
            (new ForeignKeys(IdentifierColumns::history()['relations']))->apply($connection);
            if ((new WideIdentifiers())->plan($connection) !== []) {
                throw new \RuntimeException('Identifier widening did not converge; the migration was not marked complete.');
            }
            $state = ['complete' => true];
            $save();
        };
        if ($postgres) {
            try {
                $connection->transactional(static function () use ($connection, $apply): void {
                    $connection->executeStatement("SELECT pg_advisory_xact_lock(hashtext('itsmng_legacy_to_orm'))");
                    $apply();
                });
            } catch (\Doctrine\DBAL\Exception\DriverException $error) {
                if ($error->getSQLState() === '53200' && str_contains($error->getMessage(), 'out of shared memory')) {
                    throw new \RuntimeException('PostgreSQL exhausted relation locks. Increase max_locks_per_transaction on the server and retry; this upgrade transaction was rolled back.', 0, $error);
                }
                throw $error;
            }
        } else {
            $lock = 'itsmng_orm_' . sha1($connection->getDatabase());
            if ((int)$connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
                throw new \RuntimeException('Another legacy-to-ORM migration is running.');
            }
            try {
                $apply();
            } finally {
                $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
    }
}
