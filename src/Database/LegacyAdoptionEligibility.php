<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\DomainDocuments;
use itsmng\Database\Migration\V220\DomainsPluginAdoption;
use itsmng\Database\Migration\V220\DomainsPluginSnapshot;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\V220\References;
use itsmng\Database\Migration\V220\Seeds;

/** Read-only provenance admission before canonical adoption can change old data. */
final class LegacyAdoptionEligibility
{
    /**
     * Completed historical data format, not a minimum current application version.
     * Release 1871d3f461 publishes this format only after update212to213's DML.
     * Mutable profile rights and cron settings cannot prove that transition.
     */
    private const HISTORICAL_FORMAT = '2.1.3';

    public static function assertConnection(Connection $connection): void
    {
        $states = Ledger::states($connection);
        // A partial baseline may not have created glpi_configs yet. Its known
        // installation journal owns recovery; do not misdiagnose old provenance.
        self::installed($states);
        self::proof(self::release($connection), $states);
    }

    /** Inspect before current entity mappings exist, using the supplied connection. */
    public static function release(Connection $connection): array
    {
        $manager = $connection->createSchemaManager();
        if (!$manager->tablesExist(['glpi_configs'])) {
            throw new \RuntimeException(self::diagnostic('Missing table: glpi_configs'));
        }
        $columns = $manager->listTableColumns('glpi_configs');
        foreach (['context', 'name', 'value'] as $column) {
            if (!isset($columns[$column])) {
                throw new \RuntimeException(self::diagnostic('Missing column: glpi_configs.' . $column));
            }
        }
        $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
        $aliases = ['version', 'dbversion', 'itsmversion', 'itsmdbversion'];
        $rows = $connection->fetchAllAssociative('SELECT ' . $quote('context') . ', ' . $quote('name') . ', ' . $quote('value') . ' FROM ' . $quote('glpi_configs') . ' WHERE ' . $quote('context') . ' = ? AND ' . $quote('name') . ' IN (?, ?, ?, ?) ORDER BY ' . $quote('name'), ['core', ...$aliases]);
        $release = [];
        foreach ($rows as $row) {
            // Native MySQL collation may select spelling variants. Historical
            // publication used exact keys; do not imitate collation in PHP or
            // silently normalize a different source identity into that proof.
            if ($row['context'] !== 'core' || !in_array($row['name'], $aliases, true)) {
                throw new \RuntimeException(self::diagnostic('Noncanonical historical publication key: ' . self::label($row['context']) . '.' . self::label($row['name']) . '. Reconcile the original configuration identity before retrying; no spelling or value is rewritten.'));
            }
            if (array_key_exists($row['name'], $release)) {
                // Table/column admission has not proved the unique index yet.
                // Equal values still do not establish a single publication row;
                // neither order nor an arbitrary duplicate wins provenance.
                throw new \RuntimeException(self::diagnostic('Ambiguous historical publication: duplicate core.' . $row['name'] . ' alias. Reconcile the original configuration rows before retrying; no value is chosen or removed.'));
            }
            $release[$row['name']] = $row['value'];
        }
        return $release;
    }

    /**
     * Classify captured read-only inputs; never repair labels, grant bits or save receipts.
     * Journal recognition only allows the existing owners to validate/resume their work;
     * it does not replace their schema/reference/source-fingerprint preflights.
     */
    public static function proof(array $release, array $states, string $application = ITSM_VERSION, string $schema = ITSM_SCHEMA_VERSION): string
    {
        $installedHistory = self::installed($states);
        foreach (['itsmversion' => $application, 'itsmdbversion' => $schema] as $field => $target) {
            $installed = $release[$field] ?? null;
            $comparableTarget = $target;
            if ($field === 'itsmversion' && is_string($installed)) {
                // define.php uses -dev for application builds. It does not undo
                // a proved stable historical schema/data-format transition.
                $installed = preg_replace('/-dev$/D', '', $installed);
                $comparableTarget = preg_replace('/-dev$/D', '', $target);
            }
            if (is_string($installed) && preg_match('/^\d+(?:\.\d+)+(?:[-.][a-zA-Z0-9]+)*$/D', $installed) && version_compare($installed, $comparableTarget, '>')) {
                throw new \RuntimeException('The installed ' . $field . ' (' . $release[$field] . ') is newer than these application files (' . $target . '). Use the matching application release; this updater cannot downgrade it.');
            }
        }
        $baseline = self::receipt($states, Baseline::PHASE);
        $seed = self::receipt($states, Seeds::PHASE);
        if ($installedHistory) {
            return 'canonical-installation';
        }
        if (self::adopted($baseline) && self::adopted($seed)) {
            return 'canonical-adoption';
        }
        $legacy = self::receipt($states, References::PHASE);
        if (($legacy['complete'] ?? false) === true) {
            // This is the established old completion receipt. It never recorded
            // release provenance; do not invent fields or replay history on retry.
            return 'canonical-adoption';
        }
        if (self::legacyJournal($legacy)) {
            return 'canonical-retry';
        }
        if (self::domainJournal(self::receipt($states, DomainsPluginAdoption::RECEIPT), DomainsPluginSnapshot::FORMAT)
            || self::domainJournal(self::receipt($states, DomainDocuments::GENERAL_RECEIPT), DomainDocuments::GENERAL_FORMAT)) {
            return 'canonical-prerequisite-retry';
        }
        foreach (['itsmdbversion', 'dbversion'] as $field) {
            if (($release[$field] ?? null) !== self::HISTORICAL_FORMAT) {
                throw new \RuntimeException(self::diagnostic('Completed stable historical format is not proved: ' . $field . '=' . self::label($release[$field] ?? null)));
            }
        }
        $applications = [];
        foreach (['itsmversion', 'version'] as $field) {
            if (!is_string($release[$field] ?? null) || !preg_match('/^(\d+\.\d+\.\d+)(?:-dev)?$/D', $release[$field], $parts)
                || version_compare($parts[1], self::HISTORICAL_FORMAT, '<')) {
                throw new \RuntimeException(self::diagnostic('Completed ITSM historical application release is not proved: ' . $field . '=' . self::label($release[$field] ?? null)));
            }
            $applications[$field] = $parts[1];
        }
        if ($applications['itsmversion'] !== $applications['version']) {
            throw new \RuntimeException(self::diagnostic('Contradictory ITSM historical application aliases: itsmversion=' . self::label($release['itsmversion']) . ', version=' . self::label($release['version'])));
        }
        return 'historical-release';
    }

    private static function adopted(?array $state): bool
    {
        return ($state['complete'] ?? false) === true && ($state['origin'] ?? null) === 'adopted' && ($state['data'] ?? null) === 'preserved';
    }

    private static function installed(array $states): bool
    {
        $baseline = self::receipt($states, Baseline::PHASE);
        if (($baseline['origin'] ?? null) !== 'installed') {
            return false;
        }
        $seed = self::receipt($states, Seeds::PHASE);
        if (($baseline['complete'] ?? false) !== true || ($seed['complete'] ?? false) !== true || ($seed['origin'] ?? null) !== 'installed') {
            throw new \RuntimeException('Resume the unfinished installation with db:install using this configuration before applying upgrades. Its existing baseline and seed journal will resume without replacing application data.');
        }
        return true;
    }

    private static function receipt(array $states, string $version): ?array
    {
        $state = $states[$version] ?? null;
        if ($state !== null && !is_array($state)) {
            throw new \RuntimeException('Unrecognized canonical journal: ' . $version . '. Preserve its original state and reconcile it with the actual schema/source before retrying.');
        }
        return $state;
    }

    private static function legacyJournal(?array $state): bool
    {
        if (($state['complete'] ?? null) !== false || !is_array($state['identifiers'] ?? null) || !array_is_list($state['identifiers'])
            || !is_int($state['next'] ?? null) || $state['next'] < 0 || $state['next'] > count($state['identifiers'])) {
            return false;
        }
        foreach ($state['identifiers'] as $operation) {
            if (!is_array($operation) || !is_string($operation['sql'] ?? null) || trim($operation['sql']) === '') {
                return false;
            }
            foreach (['kind', 'table', 'name'] as $field) {
                if (!is_string($operation[$field] ?? null)) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function domainJournal(?array $state, string $format): bool
    {
        if (($state['format'] ?? null) !== $format || !is_string($state['fingerprint'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $state['fingerprint'])) {
            return false;
        }
        if (($state['complete'] ?? null) === false) {
            return ($state['phase'] ?? null) === 'validated';
        }
        return ($state['complete'] ?? null) === true && is_array($state['counts'] ?? null) && is_array($state['deferred_documents'] ?? null);
    }

    private static function label(mixed $value): string
    {
        return is_string($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) : '<missing or invalid>';
    }

    private static function diagnostic(string $detail): string
    {
        return 'Historical ITSM-NG adoption provenance is missing, unsupported or contradictory. Stop application writers and use the matching historical application to complete the ITSM-NG 2.1.3 data/schema upgrade before switching to this application, then run db:migrate --apply. Restore genuine release metadata only after verifying that historical upgrade; changing labels alone does not perform its data or profile-right migrations. Current rights and cron settings may be customized and will not be overwritten. Historical MySQL scripts cannot run against the canonical ORM schema.' . "\n" . $detail;
    }
}
