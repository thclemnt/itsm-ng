<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\SchemaCheck;

/** Empty-database replay and validated adoption share one canonical history and ledger. */
final class History
{
    public const VERSIONS = [Baseline20261001::VERSION, Seeds20261001::VERSION, LegacyToOrm::VERSION, Booleans20261002::VERSION];

    /** Read-only adoption preview; baseline and seed phases are inherited, not replayed. */
    public function plan(Connection $connection): array
    {
        $pending = array_values(array_filter(self::VERSIONS, static fn (string $version) => (Ledger::state($connection, $version)['complete'] ?? false) !== true));
        $booleans = (new Booleans20261002())->plan($connection);
        return ['complete' => !$pending, 'pending' => $pending, 'legacy' => (new LegacyToOrm())->plan($connection), 'booleans' => $booleans];
    }

    public static function isInstalling(Connection $connection): bool
    {
        $baseline = Ledger::state($connection, Baseline20261001::VERSION);
        if (($baseline['origin'] ?? null) !== 'installed') {
            return false;
        }
        foreach (self::VERSIONS as $version) {
            if ((Ledger::state($connection, $version)['complete'] ?? false) !== true) {
                return true;
            }
        }
        return false;
    }

    /** Journal each MySQL table creation; PostgreSQL also retains all-or-nothing DDL. */
    public function baseline(Connection $connection, ?callable $progress = null): void
    {
        $apply = static function () use ($connection, $progress): void {
            $state = Ledger::state($connection, Baseline20261001::VERSION);
            if (($state['complete'] ?? false) === true) {
                return;
            }
            $baseline = new Baseline20261001();
            $platform = $connection->getDatabasePlatform();
            $schema = $baseline->build($platform);
            $manager = $connection->createSchemaManager();
            if ($state === null) {
                $existing = array_intersect($manager->listTableNames(), array_map(static fn ($table) => $table->getName(), $schema->getTables()));
                if ($existing) {
                    throw new \RuntimeException('Baseline replay requires an empty database or its own unfinished journal; use adoption for an existing installation.');
                }
                $state = ['complete' => false, 'origin' => 'installed', 'next' => 0];
                Ledger::save($connection, Baseline20261001::VERSION, $state);
            }
            foreach (array_values($schema->getTables()) as $offset => $table) {
                if ($offset < $state['next']) {
                    continue;
                }
                if ($manager->tablesExist([$table->getName()])) {
                    // A process can die after CREATE commits and before its checkpoint.
                    // Accept only the exact historical declaration, never a conflicting table.
                    if (!$manager->createComparator()->compareTables($table, $manager->introspectTable($table->getName()))->isEmpty()) {
                        throw new \RuntimeException('Interrupted baseline table differs from history: ' . $table->getName());
                    }
                } else {
                    foreach ($platform->getCreateTableSQL($table) as $sql) {
                        $connection->executeStatement($sql);
                    }
                }
                $progress && $progress('Created table: ' . $table->getName());
                $state['next'] = $offset + 1;
                Ledger::save($connection, Baseline20261001::VERSION, $state);
            }
            foreach ($baseline->extraSql($platform) as $sql) {
                $connection->executeStatement($sql);
            }
            Ledger::save($connection, Baseline20261001::VERSION, ['complete' => true, 'origin' => 'installed']);
        };
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $connection->transactional($apply);
        } else {
            if ($connection->isTransactionActive()) {
                throw new \RuntimeException('MySQL baseline replay must run outside an application transaction.');
            }
            $apply();
        }
    }

    public function install(\DBAdapter $database, string $language, ?callable $progress = null): void
    {
        $connection = $database->getDoctrineConnection();
        $this->locked($connection, function () use ($database, $connection, $language, $progress): void {
            $this->baseline($connection, $progress);
            \Session::loadLanguage($language, false);
            try {
                (new Seeds20261001())->apply($connection, static fn (string $text): string => __($text), $progress === null ? null : static fn () => $progress('Seed row'));
            } finally {
                \Session::loadLanguage('', false);
            }
            $database->synchronizeSequences();
            $this->upgrade($connection, $progress);
            $database->clearSchemaCache();
            $database->synchronizeSequences();
        });
    }

    /** Adopt validated existing data; never replay installation seeds onto it. */
    public function upgrade(Connection $connection, ?callable $progress = null): void
    {
        $this->locked($connection, static function () use ($connection, $progress): void {
            $baseline = Ledger::state($connection, Baseline20261001::VERSION);
            if (($baseline['origin'] ?? null) === 'installed' && (($baseline['complete'] ?? false) !== true || (Ledger::state($connection, Seeds20261001::VERSION)['complete'] ?? false) !== true)) {
                throw new \RuntimeException('Resume the unfinished installation before applying upgrades.');
            }
            // Validate every integer flag before MySQL adoption or any PostgreSQL DDL.
            (new Booleans20261002())->plan($connection);
            (new LegacyToOrm())->apply($connection, $progress);
            (new Booleans20261002())->apply($connection);
            $differences = (new SchemaCheck())->differences($connection);
            if ($differences) {
                throw new \RuntimeException("Migration history did not converge:\n" . implode("\n", $differences));
            }
            foreach ([Baseline20261001::VERSION, Seeds20261001::VERSION] as $version) {
                if (Ledger::state($connection, $version) === null) {
                    Ledger::save($connection, $version, ['complete' => true, 'origin' => 'adopted', 'data' => 'preserved']);
                }
            }
        });
    }

    private function locked(Connection $connection, callable $operation): void
    {
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $connection->transactional(static function () use ($connection, $operation): void {
                $connection->executeStatement("SELECT pg_advisory_xact_lock(hashtext('itsmng_migration_history'))");
                $operation();
            });
            return;
        }
        if ($connection->isTransactionActive()) {
            throw new \RuntimeException('Run migration history outside a MySQL application transaction.');
        }
        $lock = 'itsmng_history_' . sha1($connection->getDatabase());
        if ((int)$connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            throw new \RuntimeException('Another migration history operation is running.');
        }
        try {
            $operation();
        } finally {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
