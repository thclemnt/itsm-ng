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
use itsmng\Database\Migration\V220\RetiredMarketplaceDefaults;
use itsmng\Database\Migration\V220\Seeds;
use itsmng\Database\Migration\V220\SoftwareInstallationSubjects;
use itsmng\Database\Migration\V220\SoftwareLicenseSubjects;

/**
 * The single frozen 2.1.3 data-format to 2.2.0 ORM transition.
 * Frozen helpers in V220 and their phase IDs are internal checkpoints, not releases.
 * Retaining those keys lets interrupted MySQL installations resume their captured DDL.
 */
final class Version220 implements ReleaseMigration
{
    public const VERSION = '2.2.0';

    public function version(): string
    {
        return self::VERSION;
    }

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
        $this->assertSource($connection);
        if ((Ledger::state($connection, ExactDiscriminators::PHASE)['complete'] ?? false) === true) {
            (new ExactDiscriminators())->verify($connection);
        }
        $retirement = (new RetiredMarketplaceDefaults())->plan($connection);
        if ($retirement) {
            return ['complete' => false, 'retired_marketplace_defaults' => $retirement,
                'canonical_preflight' => 'Deferred canonical audits: archive the three exact retired marketplace defaults before auditing their remaining owners; this preview is read-only.'];
        }
        $prerequisite = (new DomainsPluginAdoption())->plan($connection);
        if ($prerequisite) {
            return ['complete' => false, 'domain_prerequisite' => $prerequisite,
                'canonical_preflight' => 'Deferred canonical audits: the frozen source graph must first be remapped in a rollback validation transaction; this preview is read-only.'];
        }
        return $this->canonicalPlan($connection);
    }

    /** Admit the original shape and this transition's own resumable removals only. */
    private function assertSource(Connection $connection): void
    {
        $historical = (new Baseline())->build($connection->getDatabasePlatform());
        $actual = $connection->createSchemaManager()->introspectSchema();
        $retired = [
            'glpi_slas' => ['calendars_id'], 'glpi_olas' => ['calendars_id'],
            'glpi_projects' => ['projecttemplates_id'],
            'glpi_planningexternalevents' => ['users_id_guests'],
            'glpi_networkportaggregates' => ['networkports_id_list'],
        ];
        $missing = [];
        foreach ($historical->getTables() as $table) {
            $name = $table->getName();
            if (!$actual->hasTable($name)) {
                $missing[] = 'Missing table: ' . $name;
                continue;
            }
            foreach ($table->getColumns() as $column) {
                if (!in_array($column->getName(), $retired[$name] ?? [], true)
                    && !$actual->getTable($name)->hasColumn($column->getName())) {
                    $missing[] = 'Missing column: ' . $name . '.' . $column->getName();
                }
            }
        }
        if ($missing) {
            throw new \RuntimeException('This schema predates or differs from the frozen ITSM-NG adoption baseline. Upgrade older releases using their matching historical application to the ITSM-NG 2.1.3 schema before switching to this application, then run db:migrate --apply.' . "\n" . implode("\n", $missing));
        }
    }

    /** All pending canonical audits, without recursively planning a source prerequisite. */
    private function canonicalPlan(Connection $connection): array
    {
        $booleans = (new Booleans())->plan($connection);
        $domainDocuments = new DomainDocuments();
        return ['phases' => self::pendingPhases(Ledger::states($connection)), 'legacy' => (new References())->plan($connection), 'booleans' => $booleans, 'project_assets' => (new ProjectAssets())->plan($connection), 'category_flags' => (new CategoryFlags())->plan($connection), 'appliance_assets' => (new ApplianceAssets())->plan($connection), 'appliance_recipients' => (new ApplianceRecipients())->plan($connection), 'operating_system_subjects' => (new OperatingSystemSubjects())->plan($connection), 'domain_documents' => $domainDocuments->plan($connection), 'domain_integration' => (new DomainIntegration())->plan($connection), 'identifier_sequences' => (new IdentifierSequences())->plan($connection), 'boolean_domains' => (new BooleanDomains())->plan($connection, true), 'exact_subject_discriminators' => (new ExactDiscriminators())->plan($connection, true, $domainDocuments), 'software_installation_subjects' => (new SoftwareInstallationSubjects())->plan($connection), 'software_license_subjects' => (new SoftwareLicenseSubjects())->plan($connection), 'processor_subjects' => (new ProcessorSubjects())->plan($connection), 'motherboard_subjects' => (new MotherboardSubjects())->plan($connection), 'memory_subjects' => (new MemorySubjects())->plan($connection), 'hard_drive_subjects' => (new HardDriveSubjects())->plan($connection), 'battery_subjects' => (new BatterySubjects())->plan($connection), 'power_supply_subjects' => (new PowerSupplySubjects())->plan($connection)];
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

    /** Frozen empty-database inputs; History owns the enclosing lock and replay. */
    public function install(\DBAdapter $database, string $language, ?callable $progress = null): void
    {
        $connection = $database->getDoctrineConnection();
        $this->baseline($connection, $progress);
        \Session::loadLanguage($language, false);
        try {
            (new Seeds())->apply($connection, static fn (string $text): string => __($text), $progress === null ? null : static fn () => $progress('Seed row'));
        } finally {
            \Session::loadLanguage('', false);
        }
        $database->synchronizeSequences();
    }

    /** Adopt validated existing data; never replay installation seeds onto it. */
    public function apply(Connection $connection, ?callable $progress = null): void
    {
        \itsmng\Database\CheckConstraintSupport::assertSupported($connection);
        // Admit provenance under the same lock before source remapping,
        // ledger bootstrap or nontransactional canonical DDL can occur.
        \itsmng\Database\LegacyAdoptionEligibility::assertConnection($connection);
        $this->assertSource($connection);
        if ((Ledger::state($connection, ExactDiscriminators::PHASE)['complete'] ?? false) === true) {
            (new ExactDiscriminators())->verify($connection);
        }
        (new RetiredMarketplaceDefaults())->apply($connection, $progress);
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
        foreach ([Baseline::PHASE, Seeds::PHASE] as $version) {
            if (Ledger::state($connection, $version) === null) {
                Ledger::save($connection, $version, ['complete' => true, 'origin' => 'adopted', 'data' => 'preserved']);
            }
        }
    }

    /** Frozen physical postconditions ignore completion flags and current entities. */
    public function verify(Connection $connection): void
    {
        if ((new RetiredMarketplaceDefaults())->plan($connection)) {
            throw new \RuntimeException('The frozen retired marketplace archival prerequisite is still pending.');
        }
        V220\Postconditions::assert($connection);
    }
}
