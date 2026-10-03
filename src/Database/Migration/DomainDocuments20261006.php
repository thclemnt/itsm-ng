<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\Types;

/** Frozen expansion of document subjects; document ownership remains independent. */
final class DomainDocuments20261006 extends StagedTypedItemMigration
{
    public const VERSION = '20261006_domain_document_subjects';
    public const GENERAL_RECEIPT = '20261006_domain_documents_deferred_v1';
    public const GENERAL_FORMAT = 'infotel-domain-documents-deferred-v1';

    public function plan(Connection $connection, ?IncomingProjectionReferences $incomingReferences = null): array
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            && strcasecmp((string)$connection->fetchOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'glpi_documents_items'"), 'InnoDB') !== 0) {
            throw new \RuntimeException('Domain document adoption requires transactional InnoDB glpi_documents_items; reconcile its storage before applying history.');
        }
        $this->deferred($connection);
        return parent::plan($connection, $incomingReferences);
    }

    protected function version(): string
    {
        return self::VERSION;
    }

    protected function table(): string
    {
        return 'glpi_documents_items';
    }

    protected function expandsTargets(): bool
    {
        return true;
    }

    protected function rebuildsProjection(Connection $connection): bool
    {
        // A crash after DDL but before its phase checkpoint safely repeats the
        // same frozen declaration. Deferred Domain rows are restored only after
        // projection, CHECK and FK phases have all succeeded.
        return (Ledger::state($connection, self::VERSION)['projection_expanded'] ?? false) !== true;
    }

    protected function journalPhase(array $state, string $phase): array
    {
        $state = parent::journalPhase($state, $phase);
        if ($phase === 'projection') {
            $state['projection_expanded'] = true;
        }
        return $state;
    }

    protected static function column(string $target): string
    {
        return match ($target) {
            'documents' => 'linked_documents_id',
            'entities' => 'subject_entities_id',
            'users' => 'subject_users_id',
            default => parent::column($target),
        };
    }

    protected static function minimumId(string $kind): int
    {
        return $kind === 'Entity' ? 0 : 1;
    }

    /** Historical snapshot: do not replace this with runtime entity discovery. */
    protected static function targets(): array
    {
        return [
            'Budget' => 'budgets', 'CartridgeItem' => 'cartridgeitems',
            'Change' => 'changes', 'Computer' => 'computers',
            'ConsumableItem' => 'consumableitems', 'Contact' => 'contacts',
            'Contract' => 'contracts', 'Document' => 'documents',
            'Entity' => 'entities', 'KnowbaseItem' => 'knowbaseitems',
            'Monitor' => 'monitors', 'NetworkEquipment' => 'networkequipments',
            'Peripheral' => 'peripherals', 'Phone' => 'phones', 'Printer' => 'printers',
            'Problem' => 'problems', 'Project' => 'projects', 'ProjectTask' => 'projecttasks',
            'Reminder' => 'reminders', 'Software' => 'softwares', 'Line' => 'lines',
            'SoftwareLicense' => 'softwarelicenses', 'Supplier' => 'suppliers',
            'Ticket' => 'tickets', 'User' => 'users', 'Certificate' => 'certificates',
            'Cluster' => 'clusters', 'ITILFollowup' => 'itilfollowups',
            'ITILSolution' => 'itilsolutions', 'ChangeTask' => 'changetasks',
            'ProblemTask' => 'problemtasks', 'TicketTask' => 'tickettasks',
            'Appliance' => 'appliances', 'Domain' => 'domains',
        ];
    }

    /** Full rows are retained in the existing adoption ledger, never in current metadata. */
    private function deferred(Connection $connection): array
    {
        $pending = [];
        $identifiers = $identities = [];
        $columns = null;
        foreach ([DomainIntegration20261006::ADOPTION => DomainIntegration20261006::FORMAT, self::GENERAL_RECEIPT => self::GENERAL_FORMAT] as $version => $format) {
            $receipt = Ledger::state($connection, $version);
            if ($receipt === null) {
                continue;
            }
            if (($receipt['complete'] ?? false) !== true || ($receipt['format'] ?? null) !== $format
                || !is_array($receipt['deferred_documents'] ?? [])) {
                throw new \RuntimeException('Unrecognized frozen Domain document adoption receipt: ' . $version);
            }
            if (($receipt['documents_restored'] ?? false) === true) {
                continue; // Later edits and purges never replay the historical row snapshot.
            }
            if (($receipt['deferred_documents'] ?? []) && ($receipt['timestamp_timezone'] ?? null) !== '+00:00') {
                throw new \RuntimeException('Deferred Domain document timestamp context is missing or unsupported: ' . $version);
            }
            $columns ??= $connection->createSchemaManager()->listTableColumns($this->table());
            $rows = [];
            foreach ($receipt['deferred_documents'] ?? [] as $record) {
                if (!is_array($record) || array_keys($record) !== ['id', 'domain_id', 'original']
                    || !is_int($record['id']) || $record['id'] < 1 || !is_int($record['domain_id']) || $record['domain_id'] < 1
                    || !is_array($record['original']) || isset($identifiers[$record['id']])) {
                    throw new \RuntimeException('Invalid frozen deferred Domain document row: ' . $version);
                }
                $row = $this->restoreValues($connection, $record);
                $identity = json_encode([$row['documents_id'], $row['domains_id'], $row['timeline_position']], JSON_THROW_ON_ERROR);
                if (isset($identities[$identity]) || $connection->fetchOne('SELECT 1 FROM glpi_documents_items WHERE id = ?', [$record['id']])) {
                    throw new \RuntimeException('Deferred Domain document collides with an existing binding: ' . $record['id']);
                }
                // The old projection can be absent during a journaled retry. Its
                // canonical new column is sufficient to detect an occupied key.
                if (isset($columns['domains_id']) && $connection->fetchOne("SELECT 1 FROM glpi_documents_items WHERE documents_id = ? AND itemtype = 'Domain' AND domains_id = ? AND timeline_position = ?", [$row['documents_id'], $row['domains_id'], $row['timeline_position']])) {
                    throw new \RuntimeException('Deferred Domain document duplicates a current owning binding: ' . $record['id']);
                }
                $identifiers[$record['id']] = $identities[$identity] = true;
                $rows[] = $row;
            }
            $pending[$version] = ['receipt' => $receipt, 'rows' => $rows];
        }
        return $pending;
    }

    private function restoreValues(Connection $connection, array $record): array
    {
        $original = $record['original'];
        $baseColumns = ['id', 'documents_id', 'items_id', 'itemtype', 'entities_id', 'is_recursive', 'date_mod', 'users_id', 'timeline_position', 'date_creation', 'date'];
        $subjectColumns = array_map(static::column(...), array_values(self::targets()));
        // domains_id belongs to this appended stage, not the retained old row.
        $oldSubjects = array_diff($subjectColumns, ['domains_id']);
        if (array_diff($baseColumns, array_keys($original))
            || array_diff(array_keys($original), [...$baseColumns, ...$oldSubjects])) {
            throw new \RuntimeException('Unsupported frozen Domain document row columns: ' . $record['id']);
        }
        foreach ($original as $value) {
            if ($value !== null && !is_string($value)) {
                throw new \RuntimeException('Invalid raw Domain document snapshot value: ' . $record['id']);
            }
        }
        if (!in_array($original['itemtype'], ['Domain', 'PluginDomainsDomain'], true)
            || $this->identifier($original['id'], 1) !== $record['id']
            || $this->identifier($original['items_id'], 1) !== $record['domain_id']) {
            throw new \RuntimeException('Deferred Domain document identity disagrees with its frozen snapshot: ' . $record['id']);
        }
        foreach ($oldSubjects as $column) {
            if (($original[$column] ?? null) !== null) {
                throw new \RuntimeException('Deferred Domain document has another owning subject: ' . $record['id'] . '.' . $column);
            }
        }
        $row = array_intersect_key($original, array_flip($baseColumns));
        unset($row['items_id']);
        $row['id'] = $record['id'];
        $row['itemtype'] = 'Domain';
        $row['domains_id'] = $record['domain_id'];
        $row['documents_id'] = $this->identifier($original['documents_id'], 1);
        $row['entities_id'] = $this->identifier($original['entities_id'], 0);
        $user = $original['users_id'] === null ? null : $this->identifier($original['users_id'], 0);
        $row['users_id'] = $user ?: null;
        $row['timeline_position'] = $this->identifier($original['timeline_position'], 0);
        if ($row['timeline_position'] > 32767 || !in_array($original['is_recursive'], ['0', '1'], true)) {
            throw new \RuntimeException('Invalid frozen Domain document timeline or recursion flag: ' . $record['id']);
        }
        $row['is_recursive'] = $original['is_recursive'] === '1';
        foreach (['date', 'date_mod', 'date_creation'] as $field) {
            if ($row[$field] === null) {
                continue;
            }
            try {
                $date = new \DateTimeImmutable($row[$field], new \DateTimeZone('UTC'));
            } catch (\Exception $error) {
                throw new \RuntimeException('Invalid frozen Domain document date: ' . $record['id'] . '.' . $field, previous: $error);
            }
            if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}/D', $row[$field])
                || $date->format('Y-m-d H:i:s') !== substr($row[$field], 0, 19)) {
                throw new \RuntimeException('Unsupported frozen Domain document date: ' . $record['id'] . '.' . $field . '; reconcile zero or invalid dates before adoption.');
            }
            if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $nativeVersion = $connection->getServerVersion();
                $version = preg_match('/(?:5\.5\.5-)?([0-9]+\.[0-9]+\.[0-9]+).*MariaDB/i', $nativeVersion, $matches)
                    ? $matches[1] : preg_replace('/[^0-9.].*$/', '', $nativeVersion);
                $maximum = !$connection->getDatabasePlatform() instanceof MySQLPlatform && version_compare($version, '11.5.0', '>=')
                    ? 4294967295 : 2147483647;
                if ($date->getTimestamp() < 1 || $date->getTimestamp() > $maximum) {
                    throw new \RuntimeException('Deferred Domain document date exceeds native TIMESTAMP range: ' . $record['id'] . '.' . $field);
                }
            }
        }
        foreach (['glpi_domains' => $row['domains_id'], 'glpi_documents' => $row['documents_id'], 'glpi_entities' => $row['entities_id'], 'glpi_users' => $row['users_id']] as $table => $id) {
            if ($id !== null && !$connection->fetchOne('SELECT 1 FROM ' . $table . ' WHERE id = ?', [$id])) {
                throw new \RuntimeException('Missing deferred Domain document parent: ' . $record['id'] . ' -> ' . $table . '.' . $id);
            }
        }
        return $row;
    }

    private function identifier(?string $value, int $minimum): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < $minimum) {
            throw new \RuntimeException('Invalid frozen Domain document numeric value.');
        }
        return $id;
    }

    protected function complete(Connection $connection): void
    {
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $timezone = $postgres ? null : $connection->fetchOne('SELECT @@session.time_zone');
        try {
            if (!$postgres) {
                $connection->executeStatement("SET SESSION time_zone = '+00:00'");
            }
            $connection->transactional(function () use ($connection): void {
                foreach ($this->deferred($connection) as $version => $pending) {
                    foreach ($pending['rows'] as $row) {
                        $connection->insert($this->table(), $row, ['is_recursive' => Types::BOOLEAN]);
                        if ((int)$connection->fetchOne('SELECT items_id FROM glpi_documents_items WHERE id = ?', [$row['id']]) !== $row['domains_id']) {
                            throw new \RuntimeException('Restored Domain document compatibility projection disagrees: ' . $row['id']);
                        }
                    }
                    $receipt = $pending['receipt'];
                    $receipt['documents_restored'] = true;
                    Ledger::save($connection, $version, $receipt);
                }
                Ledger::save($connection, self::VERSION, ['complete' => true]);
            });
        } finally {
            if (!$postgres) {
                $connection->executeStatement('SET SESSION time_zone = ?', [$timezone]);
            }
        }
    }
}
