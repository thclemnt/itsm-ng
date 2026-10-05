<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Migration\V220\ApplianceAssets;
use itsmng\Database\Migration\V220\ApplianceRecipients;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\BatterySubjects;
use itsmng\Database\Migration\V220\BooleanDomains;
use itsmng\Database\Migration\V220\Booleans;
use itsmng\Database\Migration\V220\CategoryFlags;
use itsmng\Database\Migration\V220\DomainDocuments;
use itsmng\Database\Migration\V220\DomainIntegration;
use itsmng\Database\Migration\V220\DomainsPluginAdoption;
use itsmng\Database\Migration\V220\ExactDiscriminators;
use itsmng\Database\Migration\V220\HardDriveSubjects;
use itsmng\Database\Migration\V220\IdentifierSequences;
use itsmng\Database\Migration\V220\MemorySubjects;
use itsmng\Database\Migration\V220\MotherboardSubjects;
use itsmng\Database\Migration\V220\OperatingSystemSubjects;
use itsmng\Database\Migration\V220\PowerSupplySubjects;
use itsmng\Database\Migration\V220\ProcessorSubjects;
use itsmng\Database\Migration\V220\ProjectAssets;
use itsmng\Database\Migration\V220\References;
use itsmng\Database\Migration\V220\Seeds;
use itsmng\Database\Migration\V220\SoftwareInstallationSubjects;
use itsmng\Database\Migration\V220\SoftwareLicenseSubjects;
use itsmng\Database\SchemaCheck;
use itsmng\Database\SequenceSynchronizer;

/**
 * The single frozen 2.1.3 data-format to 2.2.0 ORM transition.
 * Frozen helpers in V220 and their phase IDs are internal checkpoints, not releases.
 * Retaining those keys lets interrupted MySQL installations resume their captured DDL.
 */
final class Version220
{
    public const VERSION = '2.2.0';

    /** Internal checkpoints in the existing ledger, never separately published versions. */
    public const PHASES = [
        Baseline::PHASE,
        Seeds::PHASE,
        References::PHASE,
        Booleans::PHASE,
        ProjectAssets::PHASE,
        CategoryFlags::PHASE,
        ApplianceAssets::PHASE,
        ApplianceRecipients::PHASE,
        OperatingSystemSubjects::PHASE,
        DomainDocuments::PHASE,
        DomainIntegration::PHASE,
        IdentifierSequences::PHASE,
        BooleanDomains::PHASE,
        ExactDiscriminators::PHASE,
        SoftwareInstallationSubjects::PHASE,
        SoftwareLicenseSubjects::PHASE,
        ProcessorSubjects::PHASE,
        MotherboardSubjects::PHASE,
        MemorySubjects::PHASE,
        HardDriveSubjects::PHASE,
        BatterySubjects::PHASE,
        PowerSupplySubjects::PHASE,
    ];

    /** Application readiness uses the ledger, without planning or executing DDL. */
    public static function pendingPhases(array $states): array
    {
        return array_values(array_filter(self::PHASES, static fn (string $version): bool => ($states[$version]['complete'] ?? false) !== true));
    }

    /** Read-only adoption preview; baseline and seed phases are inherited, not replayed. */
    public function plan(Connection $connection): array
    {
        \itsmng\Database\CheckConstraintSupport::assertSupported($connection);
        \itsmng\Database\LegacyAdoptionEligibility::assertConnection($connection);
        $prerequisite = (new DomainsPluginAdoption())->plan($connection);
        if ($prerequisite) {
            return ['complete' => false, 'pending' => History::pendingVersions($connection), 'domain_prerequisite' => $prerequisite,
                'canonical_preflight' => 'Deferred canonical audits: the frozen source graph must first be remapped in a rollback validation transaction; this preview is read-only.'];
        }
        return $this->canonicalPlan($connection);
    }

    /** All pending canonical audits, without recursively planning a source prerequisite. */
    private function canonicalPlan(Connection $connection): array
    {
        $pending = History::pendingVersions($connection);
        $booleans = (new Booleans())->plan($connection);
        $domainDocuments = new DomainDocuments();
        return ['complete' => !$pending, 'pending' => $pending, 'phases' => self::pendingPhases(Ledger::states($connection)), 'legacy' => (new References())->plan($connection), 'booleans' => $booleans, 'project_assets' => (new ProjectAssets())->plan($connection), 'category_flags' => (new CategoryFlags())->plan($connection), 'appliance_assets' => (new ApplianceAssets())->plan($connection), 'appliance_recipients' => (new ApplianceRecipients())->plan($connection), 'operating_system_subjects' => (new OperatingSystemSubjects())->plan($connection), 'domain_documents' => $domainDocuments->plan($connection), 'domain_integration' => (new DomainIntegration())->plan($connection), 'identifier_sequences' => (new IdentifierSequences())->plan($connection), 'boolean_domains' => (new BooleanDomains())->plan($connection, true), 'exact_subject_discriminators' => (new ExactDiscriminators())->plan($connection, true, $domainDocuments), 'software_installation_subjects' => (new SoftwareInstallationSubjects())->plan($connection), 'software_license_subjects' => (new SoftwareLicenseSubjects())->plan($connection), 'processor_subjects' => (new ProcessorSubjects())->plan($connection), 'motherboard_subjects' => (new MotherboardSubjects())->plan($connection), 'memory_subjects' => (new MemorySubjects())->plan($connection), 'hard_drive_subjects' => (new HardDriveSubjects())->plan($connection), 'battery_subjects' => (new BatterySubjects())->plan($connection), 'power_supply_subjects' => (new PowerSupplySubjects())->plan($connection)];
    }

    public static function isInstalling(Connection $connection): bool
    {
        $baseline = Ledger::state($connection, Baseline::PHASE);
        if (($baseline['origin'] ?? null) !== 'installed') {
            return false;
        }
        if (array_key_exists('installation_complete', $baseline)) {
            return $baseline['installation_complete'] !== true;
        }
        // Early experimental installers completed these four checkpoints. Their
        // existing schema needs the remaining transition, not a fresh install.
        foreach ([Baseline::PHASE, Seeds::PHASE, References::PHASE, Booleans::PHASE] as $version) {
            if ((Ledger::state($connection, $version)['complete'] ?? false) !== true) {
                return true;
            }
        }
        return false;
    }

    /** Journal each MySQL table creation; PostgreSQL also retains all-or-nothing DDL. */
    public function baseline(Connection $connection, ?callable $progress = null): void
    {
        \itsmng\Database\CheckConstraintSupport::assertSupported($connection);
        $apply = static function () use ($connection, $progress): void {
            $state = Ledger::state($connection, Baseline::PHASE);
            if (($state['complete'] ?? false) === true) {
                return;
            }
            $baseline = new Baseline();
            $platform = $connection->getDatabasePlatform();
            $schema = $baseline->build($platform);
            $manager = $connection->createSchemaManager();
            if ($state === null) {
                $existing = array_intersect($manager->listTableNames(), array_map(static fn ($table) => $table->getName(), $schema->getTables()));
                if ($existing) {
                    throw new \RuntimeException('Baseline replay requires an empty database or its own unfinished journal; use adoption for an existing installation.');
                }
                $state = ['complete' => false, 'origin' => 'installed', 'next' => 0];
                Ledger::save($connection, Baseline::PHASE, $state);
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
                Ledger::save($connection, Baseline::PHASE, $state);
            }
            foreach ($baseline->extraSql($platform) as $sql) {
                $connection->executeStatement($sql);
            }
            Ledger::save($connection, Baseline::PHASE, ['complete' => true, 'origin' => 'installed', 'installation_complete' => false]);
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
        \itsmng\Database\CheckConstraintSupport::assertSupported($connection);
        $this->locked($connection, function () use ($database, $connection, $language, $progress): void {
            $this->baseline($connection, $progress);
            \Session::loadLanguage($language, false);
            try {
                (new Seeds())->apply($connection, static fn (string $text): string => __($text), $progress === null ? null : static fn () => $progress('Seed row'));
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
        \itsmng\Database\CheckConstraintSupport::assertSupported($connection);
        $this->locked($connection, function () use ($connection, $progress, $onComplete): void {
            // Admit provenance under the same lock before source remapping,
            // ledger bootstrap or nontransactional canonical DDL can occur.
            \itsmng\Database\LegacyAdoptionEligibility::assertConnection($connection);
            (new DomainsPluginAdoption())->apply($connection, fn () => $this->canonicalPlan($connection), $progress);
            $domainDocuments = new DomainDocuments();
            // All stored subject spellings are audited before any canonical DDL,
            // including partial legacy ownership, after the validated source trial.
            (new ExactDiscriminators())->plan($connection, true, $domainDocuments);
            // Reject newly constrained subject links before broader
            // historical audits; every preflight still completes before any DDL.
            (new ApplianceAssets())->plan($connection);
            (new ApplianceRecipients())->plan($connection);
            (new OperatingSystemSubjects())->plan($connection);
            // Jointly audit both assignment graphs before older nontransactional DDL.
            (new SoftwareInstallationSubjects())->plan($connection);
            (new SoftwareLicenseSubjects())->plan($connection);
            // Optional component ownership is audited before any earlier DDL.
            (new ProcessorSubjects())->plan($connection);
            (new MotherboardSubjects())->plan($connection);
            (new MemorySubjects())->plan($connection);
            (new HardDriveSubjects())->plan($connection);
            // Both energy graphs refuse unsupported source/plugin subjects
            // without writing before older nontransactional adoption DDL.
            (new BatterySubjects())->plan($connection);
            (new PowerSupplySubjects())->plan($connection);
            // Validate every integer flag before MySQL adoption or any PostgreSQL DDL.
            (new Booleans())->plan($connection);
            // Unsupported plugin kinds and invalid subjects refuse before
            // identifier widening or any other nontransactional adoption DDL.
            (new ProjectAssets())->plan($connection);
            (new CategoryFlags())->plan($connection);
            $domainDocuments->plan($connection);
            (new DomainIntegration())->plan($connection);
            (new BooleanDomains())->plan($connection, true);
            (new References())->apply($connection, $progress);
            (new Booleans())->apply($connection);
            (new ProjectAssets())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('ProjectAssets: ' . $phase));
            (new CategoryFlags())->apply($connection);
            (new ApplianceAssets())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('ApplianceAssets: ' . $phase));
            (new ApplianceRecipients())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('ApplianceRecipients: ' . $phase));
            (new OperatingSystemSubjects())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('OperatingSystemSubjects: ' . $phase));
            $domainDocuments->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('DomainDocuments: ' . $phase));
            (new DomainIntegration())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('DomainIntegration: ' . $phase));
            (new IdentifierSequences())->apply($connection, $progress);
            (new BooleanDomains())->apply($connection, $progress);
            (new ExactDiscriminators())->apply($connection, $progress);
            (new SoftwareInstallationSubjects())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('SoftwareInstallationSubjects: ' . $phase));
            (new SoftwareLicenseSubjects())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('SoftwareLicenseSubjects: ' . $phase));
            (new ProcessorSubjects())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('ProcessorSubjects: ' . $phase));
            (new MotherboardSubjects())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('MotherboardSubjects: ' . $phase));
            (new MemorySubjects())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('MemorySubjects: ' . $phase));
            (new HardDriveSubjects())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('HardDriveSubjects: ' . $phase));
            (new BatterySubjects())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('BatterySubjects: ' . $phase));
            (new PowerSupplySubjects())->apply($connection, $progress === null ? null : static fn (string $phase) => $progress('PowerSupplySubjects: ' . $phase));
            $differences = (new SchemaCheck())->differences($connection);
            if ($differences) {
                throw new \RuntimeException("Migration history did not converge:\n" . implode("\n", $differences));
            }
            SequenceSynchronizer::synchronize($connection);
            foreach ([Baseline::PHASE, Seeds::PHASE] as $version) {
                if (Ledger::state($connection, $version) === null) {
                    Ledger::save($connection, $version, ['complete' => true, 'origin' => 'adopted', 'data' => 'preserved']);
                }
            }
            // MySQL can commit DDL before release publication. Keep the completed
            // phase journals on failure and publish only the single release receipt
            // with its configuration changes. A retry never replays successful DDL.
            $connection->transactional(static function () use ($connection, $onComplete): void {
                if ($onComplete !== null) {
                    $onComplete();
                }
                // Close the installer with the release receipt, so a crash cannot
                // strand a fresh MySQL install between two completion markers.
                $baseline = Ledger::state($connection, Baseline::PHASE);
                if (($baseline['origin'] ?? null) === 'installed' && ($baseline['installation_complete'] ?? false) !== true) {
                    $baseline['installation_complete'] = true;
                    Ledger::save($connection, Baseline::PHASE, $baseline);
                }
                if ((Ledger::state($connection, self::VERSION)['complete'] ?? false) !== true) {
                    Ledger::save($connection, self::VERSION, ['complete' => true]);
                }
            });
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
