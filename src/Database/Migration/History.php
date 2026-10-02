<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\SchemaCheck;
use itsmng\Database\SequenceSynchronizer;

/** Empty-database replay and validated adoption share one canonical history and ledger. */
final class History
{
    public const VERSIONS = [Baseline20261001::VERSION, Seeds20261001::VERSION, LegacyToOrm::VERSION, Booleans20261002::VERSION, ProjectAssets20261003::VERSION, CategoryFlags20261004::VERSION, ApplianceAssets20261005::VERSION, ApplianceRecipients20261005::VERSION, OperatingSystemSubjects20261006::VERSION, DomainDocuments20261006::VERSION, DomainIntegration20261006::VERSION];

    /** Application readiness uses the ledger, without planning or executing DDL. */
    public static function pendingVersions(Connection $connection): array
    {
        $states = Ledger::states($connection);
        return array_values(array_filter(self::VERSIONS, static fn (string $version): bool => ($states[$version]['complete'] ?? false) !== true));
    }

    /** Read-only adoption preview; baseline and seed phases are inherited, not replayed. */
    public function plan(Connection $connection): array
    {
        $prerequisite = (new DomainsPluginAdoption20261006())->plan($connection);
        if ($prerequisite) {
            return ['complete' => false, 'pending' => self::pendingVersions($connection), 'domain_prerequisite' => $prerequisite,
                'canonical_preflight' => 'Deferred canonical audits: the frozen source graph must first be remapped in a rollback validation transaction; this preview is read-only.'];
        }
        return $this->canonicalPlan($connection);
    }

    /** All pending canonical audits, without recursively planning a source prerequisite. */
    private function canonicalPlan(Connection $connection): array
    {
        $pending = self::pendingVersions($connection);
        $booleans = (new Booleans20261002())->plan($connection);
        return ['complete' => !$pending, 'pending' => $pending, 'legacy' => (new LegacyToOrm())->plan($connection), 'booleans' => $booleans, 'project_assets' => (new ProjectAssets20261003())->plan($connection), 'category_flags' => (new CategoryFlags20261004())->plan($connection), 'appliance_assets' => (new ApplianceAssets20261005())->plan($connection), 'appliance_recipients' => (new ApplianceRecipients20261005())->plan($connection), 'operating_system_subjects' => (new OperatingSystemSubjects20261006())->plan($connection), 'domain_documents' => (new DomainDocuments20261006())->plan($connection), 'domain_integration' => (new DomainIntegration20261006())->plan($connection)];
    }

    public static function isInstalling(Connection $connection): bool
    {
        $baseline = Ledger::state($connection, Baseline20261001::VERSION);
        if (($baseline['origin'] ?? null) !== 'installed') {
            return false;
        }
        if (array_key_exists('installation_complete', $baseline)) {
            return $baseline['installation_complete'] !== true;
        }
        // Former installers completed these four versions. Appending a new
        // upgrade must not turn their existing schema into an unfinished install.
        foreach ([Baseline20261001::VERSION, Seeds20261001::VERSION, LegacyToOrm::VERSION, Booleans20261002::VERSION] as $version) {
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
            Ledger::save($connection, Baseline20261001::VERSION, ['complete' => true, 'origin' => 'installed', 'installation_complete' => false]);
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
    public function upgrade(Connection $connection, ?callable $progress = null, ?callable $onComplete = null): void
    {
        $this->locked($connection, function () use ($connection, $progress, $onComplete): void {
            $baseline = Ledger::state($connection, Baseline20261001::VERSION);
            if (($baseline['origin'] ?? null) === 'installed' && (($baseline['complete'] ?? false) !== true || (Ledger::state($connection, Seeds20261001::VERSION)['complete'] ?? false) !== true)) {
                throw new \RuntimeException('Resume the unfinished installation before applying upgrades.');
            }
            (new DomainsPluginAdoption20261006())->apply($connection, fn () => $this->canonicalPlan($connection), $progress);
            // Reject newly constrained subject links before broader
            // historical audits; every preflight still completes before any DDL.
            (new ApplianceAssets20261005())->plan($connection);
            (new ApplianceRecipients20261005())->plan($connection);
            (new OperatingSystemSubjects20261006())->plan($connection);
            // Validate every integer flag before MySQL adoption or any PostgreSQL DDL.
            (new Booleans20261002())->plan($connection);
            // Unsupported plugin kinds and invalid subjects refuse before
            // identifier widening or any other nontransactional adoption DDL.
            (new ProjectAssets20261003())->plan($connection);
            (new CategoryFlags20261004())->plan($connection);
            (new DomainDocuments20261006())->plan($connection);
            (new DomainIntegration20261006())->plan($connection);
            (new LegacyToOrm())->apply($connection, $progress);
            (new Booleans20261002())->apply($connection);
            (new ProjectAssets20261003())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('ProjectAssets20261003: ' . $phase));
            (new CategoryFlags20261004())->apply($connection);
            (new ApplianceAssets20261005())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('ApplianceAssets20261005: ' . $phase));
            (new ApplianceRecipients20261005())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('ApplianceRecipients20261005: ' . $phase));
            (new OperatingSystemSubjects20261006())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('OperatingSystemSubjects20261006: ' . $phase));
            (new DomainDocuments20261006())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('DomainDocuments20261006: ' . $phase));
            (new DomainIntegration20261006())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('DomainIntegration20261006: ' . $phase));
            $differences = (new SchemaCheck())->differences($connection);
            if ($differences) {
                throw new \RuntimeException("Migration history did not converge:\n" . implode("\n", $differences));
            }
            SequenceSynchronizer::synchronize($connection);
            foreach ([Baseline20261001::VERSION, Seeds20261001::VERSION] as $version) {
                if (Ledger::state($connection, $version) === null) {
                    Ledger::save($connection, $version, ['complete' => true, 'origin' => 'adopted', 'data' => 'preserved']);
                }
            }
            $baseline = Ledger::state($connection, Baseline20261001::VERSION);
            if (($baseline['origin'] ?? null) === 'installed' && ($baseline['installation_complete'] ?? false) !== true) {
                $baseline['installation_complete'] = true;
                Ledger::save($connection, Baseline20261001::VERSION, $baseline);
            }
            if ($onComplete !== null) {
                $onComplete();
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
