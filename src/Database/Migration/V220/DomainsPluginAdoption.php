<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Migration\Ledger;
use RuntimeException;

/** Frozen data prerequisite; validates the remapped graph before adoption DDL. */
final class DomainsPluginAdoption
{
    public const RECEIPT = '20261006_domains_plugin_adoption_v1';
    private const CURRENT_IMPORT = '20261006_domains_plugin_import_v1';

    /** Source-only preview deliberately does not claim canonical preflights passed. */
    public function plan(Connection $connection): array
    {
        // Completed canonical installations use the lifecycle importer. This
        // prerequisite exists to unblock frozen legacy adoption, not to import
        // new plugin aggregates through DBAL on a running current application.
        if ((Ledger::state($connection, References::PHASE)['complete'] ?? false) === true) {
            return [];
        }
        $tables = array_fill_keys($connection->createSchemaManager()->listTableNames(), true);
        $sourcePresent = isset($tables['glpi_plugin_domains_profiles']) || (bool)array_intersect_key($tables, DomainsPluginSnapshot::definition()['source']);
        $bindings = $this->identities($connection, $tables);
        $general = (Ledger::state($connection, References::PHASE)['complete'] ?? false) !== true
            ? $connection->fetchAllAssociative("SELECT * FROM glpi_documents_items WHERE itemtype = 'Domain' ORDER BY id") : [];
        $adoption = Ledger::state($connection, self::RECEIPT);
        $current = Ledger::state($connection, self::CURRENT_IMPORT);
        $receipt = $adoption ?? $current;
        $registration = $bindings || $sourcePresent || $receipt !== null ? $this->registration($connection) : null;
        $validated = $receipt ?? Ledger::state($connection, DomainDocuments::GENERAL_RECEIPT);
        if ($receipt !== null && ($receipt['complete'] ?? false) === true) {
            foreach ([$adoption, $current] as $proof) {
                if ($proof !== null) {
                    $this->verifyReceipt($connection, $proof, $bindings);
                }
            }
            return [];
        }
        if ($receipt === null && ($validated['complete'] ?? false) === true
            && (($validated['format'] ?? null) !== DomainDocuments::GENERAL_FORMAT || $general)) {
            throw new RuntimeException('New or unrecognized historical Domain document bindings after committed deferral; reconcile the retained receipt before retry.');
        }
        if ($validated !== null && ($validated['complete'] ?? false) !== true && ($validated['phase'] ?? null) !== 'validated') {
            throw new RuntimeException('Unrecognized interrupted frozen Domains prerequisite journal.');
        }
        if (!$bindings && !$general && !$sourcePresent) {
            return [];
        }
        $plugin = (bool)$bindings || $sourcePresent;
        $source = $plugin ? DomainsPluginSnapshot::read($connection) : [];
        if ($validated !== null && ($validated['complete'] ?? false) !== true && ($validated['format'] ?? null) !== ($plugin ? DomainsPluginSnapshot::FORMAT : DomainDocuments::GENERAL_FORMAT)) {
            throw new RuntimeException('Interrupted frozen Domains prerequisite format does not match its source.');
        }
        $records = $plugin ? $this->records($connection, $source) : [];
        $incoming = [];
        $domainScopes = [];
        foreach ($records as $record) {
            $incoming[$record['table']][$record['values']['id']] = true;
            if ($record['table'] === 'glpi_domains') {
                $domainScopes[$record['values']['id']] = ['entity' => $record['values']['entities_id'], 'recursive' => (bool)$record['values']['is_recursive']];
            }
        }
        $updates = $documents = $retained = [];
        foreach ($bindings as $binding) {
            $table = $binding['table'];
            $field = $binding['field'];
            $row = $binding['row'];
            $kind = $row[$field];
            $domain = $kind === 'PluginDomainsDomain';
            $target = $domain ? 'Domain' : 'DomainType';
            $targetTable = $domain ? 'glpi_domains' : 'glpi_domaintypes';
            if ($table === 'glpi_crontasks') {
                continue; // The frozen scheduler policy preserves or retires its identity.
            }
            if ($table === 'glpi_logs' && $field === 'itemtype_link') {
                $updates[] = ['table' => $table, 'id' => (int)$row['id'], 'values' => [$field => $target]];
                continue;
            }
            if ($table === 'glpi_impactrelations') {
                if (!$domain || !in_array($field, ['itemtype_source', 'itemtype_impacted'], true)) {
                    throw new RuntimeException('Unsupported frozen Domains impact role: ' . $row['id'] . '.' . $field);
                }
                $id = DomainsPluginSnapshot::integer($row[$field === 'itemtype_source' ? 'items_id_source' : 'items_id_impacted'], 'impact.' . $row['id'], 1);
                $this->incoming($incoming, $targetTable, $id, $table . '.' . $row['id']);
                $this->coherent(
                    $connection,
                    $this->impactScope($connection, $row['itemtype_source'], (int)$row['items_id_source'], $domainScopes),
                    $this->impactScope($connection, $row['itemtype_impacted'], (int)$row['items_id_impacted'], $domainScopes),
                    $table . '.' . $row['id']
                );
                $updates[] = ['table' => $table, 'id' => (int)$row['id'], 'values' => [$field => $target]];
                continue;
            }
            if ($field !== 'itemtype') {
                throw new RuntimeException('Unsupported frozen Domains identity role: ' . $table . '.' . $field);
            }
            if (array_key_exists('items_id', $row)) {
                $id = DomainsPluginSnapshot::integer($row['items_id'], $table . '.' . $row['id'] . '.items_id', 1);
                if ($table === 'glpi_logs' && !isset($incoming[$targetTable][$id])) {
                    $retained[] = ['table' => $table, 'id' => (int)$row['id'], 'field' => 'itemtype'];
                    continue; // Retired audit subjects never adopt an unrelated core ID.
                }
                $this->incoming($incoming, $targetTable, $id, $table . '.' . $row['id']);
                $owner = DomainsPluginSnapshot::definition()['relation_owners'][$table] ?? null;
                if ($domain && $owner !== null) {
                    $this->coherent($connection, $this->coreScope($connection, $owner, (int)$row[$owner['column']]), $domainScopes[$id], $table . '.' . $row['id']);
                }
                if (!$domain && $table !== 'glpi_dropdowntranslations') {
                    throw new RuntimeException('Unsupported frozen Domains type binding: ' . $table . '.' . $row['id']);
                }
                if ($table === 'glpi_documents_items') {
                    $documents[] = $this->document($connection, $row, $id);
                    continue;
                }
                $values = ['itemtype' => $target] + $this->typedValues($connection, $table, $target, $id);
                $updates[] = ['table' => $table, 'id' => (int)$row['id'], 'values' => $values];
            } else {
                $updates[] = ['table' => $table, 'id' => (int)$row['id'], 'values' => ['itemtype' => $target] + $this->classValues($table, $row, $target)];
            }
        }
        foreach ($general as $row) {
            if ($row['itemtype'] !== 'Domain') {
                throw new RuntimeException('Noncanonical historical Domain document kind: ' . $row['id']);
            }
            $id = DomainsPluginSnapshot::integer($row['items_id'], 'document.' . $row['id'], 1);
            if (!$connection->fetchOne('SELECT 1 FROM glpi_domains WHERE id = ?', [$id])) {
                throw new RuntimeException('Missing historical core Domain document subject: ' . $row['id']);
            }
            $this->coherent(
                $connection,
                $this->coreScope($connection, DomainsPluginSnapshot::definition()['relation_owners']['glpi_documents_items'], (int)$row['documents_id']),
                $this->coreScope($connection, DomainsPluginSnapshot::definition()['impact_kinds']['Domain'], $id),
                'glpi_documents_items.' . $row['id']
            );
            $documents[] = $this->document($connection, $row, $id);
        }
        usort($documents, static fn ($a, $b) => $a['id'] <=> $b['id']);
        $policy = $plugin ? (new DomainsPluginPolicy())->plan($connection, $source) : ['grants' => [], 'updates' => [], 'retained_source_rights' => []];
        foreach ($policy['updates'] as $update) {
            if ($update['table'] === 'glpi_crontasks' && ($update['values']['state'] ?? null) === 0) {
                $retained[] = ['table' => 'glpi_crontasks', 'id' => $update['id'], 'field' => 'itemtype'];
            }
        }
        $identities = [];
        foreach ($documents as $document) {
            $key = implode(':', [$document['original']['documents_id'], $document['domain_id'], $document['original']['timeline_position']]);
            if (isset($identities[$key])) {
                throw new RuntimeException('Duplicate deferred frozen Domain document binding: ' . $document['id']);
            }
            $identities[$key] = true;
        }
        $version = $plugin ? self::RECEIPT : DomainDocuments::GENERAL_RECEIPT;
        $deferred = [];
        foreach ($source['glpi_plugin_domains_domains'] ?? [] as $row) {
            $supplier = DomainsPluginSnapshot::integer($row['suppliers_id'], 'domain.supplier');
            $deferred[] = ['id' => DomainsPluginSnapshot::integer($row['id'], 'domain.id', 1), 'suppliers_id' => $supplier ?: null,
                'is_helpdesk_visible' => DomainsPluginSnapshot::flag($row['is_helpdesk_visible'], 'domain.visibility')];
        }
        $receipt = ['complete' => true, 'format' => $plugin ? DomainsPluginSnapshot::FORMAT : DomainDocuments::GENERAL_FORMAT,
            'fingerprint' => $plugin ? DomainsPluginSnapshot::fingerprint($source) : hash('sha256', json_encode($documents, JSON_THROW_ON_ERROR)),
            'counts' => $plugin ? ['types' => count($source['glpi_plugin_domains_domaintypes']), 'domains' => count($source['glpi_plugin_domains_domains']), 'items' => count($source['glpi_plugin_domains_domains_items']), 'configs' => count($source['glpi_plugin_domains_configs'])] : ['documents' => count($documents)],
            'bindings' => $updates, 'retained_bindings' => $retained, 'retained_source_rights' => $policy['retained_source_rights'],
            'deferred_domains' => $deferred, 'deferred_documents' => $documents, 'documents_restored' => false, 'timestamp_timezone' => '+00:00'];
        $receipt['source_plugin'] = $registration;
        if ($validated !== null && ($validated['complete'] ?? false) !== true && ($validated['fingerprint'] ?? null) !== $receipt['fingerprint']) {
            throw new RuntimeException('Frozen Domains source changed after the validated prerequisite journal.');
        }
        $this->transactionalTables($connection, array_unique([...array_column($records, 'table'), ...array_column($updates, 'table'), ...array_column($policy['updates'], 'table'), ...($policy['grants'] ? ['glpi_profilerights'] : []), ...($documents ? ['glpi_documents_items'] : []), ...($plugin ? array_keys(DomainsPluginSnapshot::definition()['source']) : [])]));
        return ['kind' => 'data_prerequisite', 'description' => $plugin ? 'Frozen Domains export adoption with stable IDs; source registrations and unrelated core records are retained.' : 'Frozen pre-existing core Domain document deferral and restoration; no plugin source is required.',
            'version' => $version, 'receipt' => $receipt, 'records' => $records, 'updates' => $updates, 'policy' => $policy,
            'canonical_preflight' => 'Deferred: validated against the remapped graph in a rolled-back transaction before any MySQL ledger bootstrap or canonical DDL.'];
    }

    /** Run under History's lock with application and historical plugin writers stopped. */
    public function apply(Connection $connection, callable $canonicalPreflight, ?callable $progress = null): void
    {
        $plan = $this->plan($connection);
        if (!$plan) {
            return;
        }
        $connection->beginTransaction();
        try {
            $this->lockSource($connection, $plan);
            $this->remap($connection, $plan);
            // On retry the bootstrap ledger already exists. Expose the full
            // receipt only inside this rollback trial, so canonical audits see
            // deferred rows instead of mistaking its pending marker for data.
            // A ledgerless first trial still performs no CREATE TABLE at all.
            if (Ledger::assertTransactional($connection)) {
                Ledger::save($connection, $plan['version'], $plan['receipt']);
            }
            $canonicalPreflight();
        } finally {
            $connection->rollBack();
        }
        // No ledger DDL runs until the complete remapped core graph has passed.
        // MySQL cannot retain row locks across bootstrap CREATE TABLE; the
        // migration lock and stopped writers remain required through this gap.
        Ledger::save($connection, $plan['version'], ['complete' => false, 'format' => $plan['receipt']['format'], 'fingerprint' => $plan['receipt']['fingerprint'], 'phase' => 'validated']);
        $connection->transactional(function () use ($connection, $canonicalPreflight, $progress, $plan): void {
            $this->lockSource($connection, $plan);
            $retry = $this->plan($connection);
            if (!$retry || $retry['receipt']['fingerprint'] !== $plan['receipt']['fingerprint']) {
                throw new RuntimeException('Frozen Domains source changed after validation; stop writers and reconcile before retry.');
            }
            $this->remap($connection, $retry);
            // Pending canonical stages validate the full deferred values from
            // this same transaction. An incomplete bootstrap journal cannot
            // stand in for the data receipt; validation failures roll back both.
            Ledger::save($connection, $plan['version'], $retry['receipt']);
            $canonicalPreflight();
            $progress && $progress('Frozen Domains identity prerequisite committed; canonical history remains pending.');
        });
    }

    private function records(Connection $connection, array $source): array
    {
        $result = $incoming = [];
        $types = $typeRows = [];
        foreach ($source['glpi_plugin_domains_domaintypes'] as $row) {
            $id = DomainsPluginSnapshot::integer($row['id'], 'type.id', 1);
            $types[$id] = true;
            $typeRows[$id] = $row;
            $result[] = $this->record($connection, 'glpi_domaintypes', $row, ['is_recursive']);
        }
        $domains = $domainScopes = [];
        foreach ($source['glpi_plugin_domains_domains'] as $row) {
            $id = DomainsPluginSnapshot::integer($row['id'], 'domain.id', 1);
            $domains[$id] = true;
            $type = DomainsPluginSnapshot::integer($row['plugin_domains_domaintypes_id'], 'domain.type');
            if ($type > 0 && !isset($types[$type])) {
                throw new RuntimeException('Missing frozen plugin DomainType: domain ' . $id . ' -> ' . $type);
            }
            $entity = DomainsPluginSnapshot::integer($row['entities_id'], 'domain.entity');
            $domainScopes[$id] = ['entity' => $entity, 'recursive' => DomainsPluginSnapshot::flag($row['is_recursive'], 'domain.recursion')];
            if ($type > 0) {
                $this->scoped($connection, $entity, (int)$typeRows[$type]['entities_id'], DomainsPluginSnapshot::flag($typeRows[$type]['is_recursive'], 'type.recursion'), 'domain.' . $id . '.type');
            }
            foreach (['users_id_tech' => 'glpi_users', 'groups_id_tech' => 'glpi_groups', 'suppliers_id' => 'glpi_suppliers'] as $field => $table) {
                $parent = DomainsPluginSnapshot::integer($row[$field], 'domain.' . $id . '.' . $field);
                if ($parent > 0 && !$connection->fetchOne('SELECT 1 FROM ' . $table . ' WHERE id = ?', [$parent])) {
                    throw new RuntimeException('Missing frozen Domains source parent: ' . $id . '.' . $field);
                }
                if ($field === 'suppliers_id' && $parent > 0) {
                    $supplier = $connection->fetchAssociative('SELECT entities_id, is_recursive FROM glpi_suppliers WHERE id = ?', [$parent]);
                    $this->scoped($connection, $entity, (int)$supplier['entities_id'], (bool)$supplier['is_recursive'], 'domain.' . $id . '.supplier');
                }
            }
            DomainsPluginSnapshot::flag($row['is_helpdesk_visible'], 'domain.' . $id . '.visibility');
            $row['domaintypes_id'] = $type;
            unset($row['plugin_domains_domaintypes_id'], $row['suppliers_id'], $row['is_helpdesk_visible']);
            foreach (['date_creation', 'date_expiration', 'date_mod'] as $field) {
                $row[$field] = $this->date($connection, $row[$field], 'domain.' . $id . '.' . $field);
            }
            $result[] = $this->record($connection, 'glpi_domains', $row, ['is_recursive', 'is_deleted'], ['domaintypes_id', 'users_id_tech', 'groups_id_tech']);
        }
        $subjects = ['Computer' => 'glpi_computers', 'Monitor' => 'glpi_monitors', 'NetworkEquipment' => 'glpi_networkequipments',
            'Peripheral' => 'glpi_peripherals', 'Phone' => 'glpi_phones', 'Printer' => 'glpi_printers', 'Software' => 'glpi_softwares'];
        foreach ($source['glpi_plugin_domains_domains_items'] as $row) {
            $owner = DomainsPluginSnapshot::integer($row['plugin_domains_domains_id'], 'link.owner', 1);
            $subject = DomainsPluginSnapshot::integer($row['items_id'], 'link.subject', 1);
            if (!isset($domains[$owner]) || !isset($subjects[$row['itemtype']]) || !$connection->fetchOne('SELECT 1 FROM ' . ($subjects[$row['itemtype']] ?? 'glpi_domains') . ' WHERE id = ?', [$subject])) {
                throw new RuntimeException('Invalid or unsupported frozen Domains asset link: ' . $row['id'] . '.' . $row['itemtype']);
            }
            $this->coherent($connection, $domainScopes[$owner], $this->coreScope($connection, DomainsPluginSnapshot::definition()['impact_kinds'][$row['itemtype']], $subject), 'glpi_domains_items.' . $row['id']);
            $row['domains_id'] = $owner;
            $columns = $connection->createSchemaManager()->listTableColumns('glpi_domains_items');
            $row['domainrelations_id'] = $columns['domainrelations_id']->getNotnull() ? 0 : null;
            unset($row['plugin_domains_domains_id']);
            $record = $this->record($connection, 'glpi_domains_items', $row);
            $typed = $this->typedValues($connection, 'glpi_domains_items', $row['itemtype'], $subject);
            if ($typed) {
                unset($record['values']['items_id']);
                $record['values'] += $typed;
            }
            if ($connection->fetchOne('SELECT 1 FROM glpi_domains_items WHERE domains_id = ? AND itemtype = ? AND items_id = ?', [$owner, $row['itemtype'], $subject])) {
                throw new RuntimeException('Frozen Domains asset link duplicates an existing binding: ' . $row['id']);
            }
            $key = $owner . ':' . $row['itemtype'] . ':' . $subject;
            if (isset($incoming[$key])) {
                throw new RuntimeException('Duplicate frozen Domains asset link: ' . $row['id']);
            }
            $incoming[$key] = true;
            $result[] = $record;
        }
        $ids = [];
        foreach ($result as $record) {
            $id = $record['values']['id'];
            if (isset($ids[$record['table']][$id])) {
                throw new RuntimeException('Duplicate frozen Domains aggregate identifier: ' . $record['table'] . '.' . $id);
            }
            $ids[$record['table']][$id] = true;
        }
        return $result;
    }

    private function record(Connection $connection, string $table, array $values, array $flags = [], array $optional = []): array
    {
        $id = DomainsPluginSnapshot::integer($values['id'], $table . '.id', 1);
        $columns = $connection->createSchemaManager()->listTableColumns($table);
        $values['id'] = $id;
        $types = [];
        foreach ($values as $field => &$value) {
            $column = $columns[$field] ?? throw new RuntimeException('Unsupported frozen Domains target layout: ' . $table . '.' . $field);
            $type = Type::lookupName($column->getType());
            if ($value === null) {
                if ($column->getNotnull()) {
                    throw new RuntimeException('Frozen Domains null cannot fit required target: ' . $table . '.' . $field);
                }
                continue;
            }
            if (in_array($field, $flags, true)) {
                $value = DomainsPluginSnapshot::flag($value, $table . '.' . $field);
                if ($type === Types::BOOLEAN) {
                    $types[$field] = Types::BOOLEAN;
                } else {
                    $value = (int)$value;
                }
            } elseif (in_array($type, [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true)) {
                $value = DomainsPluginSnapshot::integer($value, $table . '.' . $field);
                $maximum = $type === Types::SMALLINT ? 32767 : ($type === Types::INTEGER ? ($column->getUnsigned() ? 4294967295 : 2147483647) : PHP_INT_MAX);
                if ($value > $maximum) {
                    throw new RuntimeException('Frozen Domains identifier exceeds native target width: ' . $table . '.' . $field . '; adoption cannot insert it before widening.');
                }
                if ($value === 0 && in_array($field, $optional, true) && !$column->getNotnull()) {
                    $value = null;
                }
            } elseif ($type === Types::STRING && $column->getLength() !== null && mb_strlen((string)$value) > $column->getLength()) {
                throw new RuntimeException('Frozen Domains text exceeds supported target length: ' . $table . '.' . $field);
            }
        }
        unset($value);
        // Validate native width before binding an identifier to a narrow
        // PostgreSQL column: otherwise the driver throws instead of reporting
        // the supported pre-adoption boundary without writes.
        if ($connection->fetchOne('SELECT 1 FROM ' . $table . ' WHERE id = ?', [$id])) {
            throw new RuntimeException('Frozen Domains target identifier collision: ' . $table . '.' . $id . '; names/content never establish ownership.');
        }
        if (isset($values['entities_id']) && !$connection->fetchOne('SELECT 1 FROM glpi_entities WHERE id = ?', [$values['entities_id']])) {
            throw new RuntimeException('Missing frozen Domains entity: ' . $table . '.' . $id);
        }
        return ['table' => $table, 'values' => $values, 'types' => $types];
    }

    private function date(Connection $connection, ?string $value, string $field, bool $utc = false): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}(?: [0-9]{2}:[0-9]{2}:[0-9]{2})?$/D', $value)) {
            throw new RuntimeException('Invalid frozen Domains calendar date: ' . $field . '; zero dates require explicit reconciliation.');
        }
        $full = strlen($value) === 10 ? $value . ' 00:00:00' : $value;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $full, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $full) {
            throw new RuntimeException('Invalid frozen Domains calendar date: ' . $field);
        }
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $instant = $utc ? $date->getTimestamp() : $connection->fetchOne('SELECT UNIX_TIMESTAMP(?)', [$full]);
            $nativeVersion = $connection->getServerVersion();
            $version = preg_match('/(?:5\.5\.5-)?([0-9]+\.[0-9]+\.[0-9]+).*MariaDB/i', $nativeVersion, $matches)
                ? $matches[1] : preg_replace('/[^0-9.].*$/', '', $nativeVersion);
            $maximum = !$connection->getDatabasePlatform() instanceof MySQLPlatform && version_compare($version, '11.5.0', '>=')
                ? 4294967295 : 2147483647;
            if ($instant === null || (int)$instant < 1 || (int)$instant > $maximum) {
                throw new RuntimeException('Frozen Domains calendar date exceeds native TIMESTAMP: ' . $field . '; calendar schema policy must be resolved before adoption.');
            }
        }
        return $full;
    }

    private function incoming(array $incoming, string $table, int $id, string $binding): void
    {
        if (!isset($incoming[$table][$id])) {
            throw new RuntimeException('Missing frozen Domains plugin binding subject: ' . $binding . ' -> ' . $table . '.' . $id);
        }
    }

    private function typedValues(Connection $connection, string $table, string $kind, int $id): array
    {
        $typed = DomainsPluginSnapshot::definition()['identities'][$table]['typed']['items_id']['selections'] ?? [];
        if (!$typed) {
            return [];
        }
        if (!isset($typed[$kind])) {
            throw new RuntimeException('Frozen typed binding cannot own ' . $kind . ': ' . $table);
        }
        $columns = $connection->createSchemaManager()->listTableColumns($table);
        $known = array_column($typed, 'column');
        $present = array_intersect($known, array_keys($columns));
        if (!$present) {
            return []; // The historical scalar is converted by canonical replay.
        }
        if (count($present) !== count($known)) {
            throw new RuntimeException('Ambiguous partially adopted frozen Domains ownership: ' . $table);
        }
        $values = array_fill_keys($known, null);
        $values[$typed[$kind]['column']] = $id;
        return $values;
    }

    private function identities(Connection $connection, array $tables): array
    {
        $result = [];
        $manager = $connection->createSchemaManager();
        $definition = DomainsPluginSnapshot::definition();
        // Discovery needs only column existence, not a new full DBAL column
        // declaration for every frozen identity table on each preflight/retry.
        $available = [];
        foreach ($connection->fetchAllAssociative('SELECT table_name AS table_name, column_name AS column_name FROM information_schema.columns WHERE table_schema = '
            . ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'DATABASE()' : 'ANY(current_schemas(false))')) as $column) {
            $available[$column['table_name']][$column['column_name']] = true;
        }
        foreach ($definition['identities'] as $table => $identity) {
            if (!isset($tables[$table])) {
                continue;
            }
            foreach ($identity['identity_fields'] as $field) {
                if (!isset($available[$table][$field])) {
                    continue;
                }
                $quote = $connection->quoteIdentifier(...);
                foreach ($connection->fetchAllAssociative('SELECT * FROM ' . $quote($table) . ' WHERE LOWER(TRIM(' . $quote($field) . ')) LIKE ? ORDER BY id', ['plugindomains%']) as $row) {
                    if (!in_array($row[$field], DomainsPluginSnapshot::KINDS, true)) {
                        throw new RuntimeException('Unsupported frozen Domains identity spelling: ' . $table . '.' . $row['id'] . '.' . $field . '=' . $row[$field]);
                    }
                    $result[] = ['table' => $table, 'field' => $field, 'row' => $row];
                }
            }
        }
        // Unmodeled extension rows require a verified adapter, not a guessed
        // generic items_id owner. Exact class values diagnose rather than mutate.
        $knownCore = $definition['core_tables'];
        foreach (array_keys($tables) as $table) {
            if (in_array($table, $knownCore, true) || isset($definition['source'][$table]) || $table === Ledger::TABLE) {
                continue;
            }
            foreach ($manager->listTableColumns($table) as $column) {
                if (!in_array(Type::lookupName($column->getType()), [Types::STRING, Types::TEXT], true)) {
                    continue;
                }
                $quote = $connection->quoteIdentifier(...);
                $row = $connection->fetchAssociative('SELECT * FROM ' . $quote($table) . ' WHERE LOWER(TRIM(' . $quote($column->getName()) . ')) LIKE ? LIMIT 1', ['plugindomains%']);
                if ($row) {
                    throw new RuntimeException('Unsupported external frozen Domains identity: ' . $table . '.' . ($row['id'] ?? '?') . '.' . $column->getName());
                }
            }
        }
        return $result;
    }

    private function document(Connection $connection, array $row, int $domain): array
    {
        $this->incomingDocumentReferences($connection);
        $columns = $connection->createSchemaManager()->listTableColumns('glpi_documents_items');
        $base = ['id', 'documents_id', 'items_id', 'itemtype', 'entities_id', 'is_recursive', 'date_mod', 'users_id', 'timeline_position', 'date_creation', 'date'];
        $subjects = DomainsPluginSnapshot::definition()['identities']['glpi_documents_items']['typed']['items_id']['selections'];
        $old = array_diff(array_column($subjects, 'column'), ['domains_id']);
        if (array_diff($base, array_keys($columns)) || array_diff(array_keys($columns), [...$base, ...$old])) {
            throw new RuntimeException('Unsupported frozen Domain document physical layout: ' . $row['id']);
        }
        $previous = null;
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $native = $connection->fetchAllKeyValue("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='glpi_documents_items'");
            foreach (['date_mod', 'date_creation', 'date'] as $field) {
                if (($native[$field] ?? null) !== 'timestamp') {
                    throw new RuntimeException('Frozen Domain document dates require native TIMESTAMP: ' . $field);
                }
            }
            $previous = (string)$connection->fetchOne('SELECT @@session.time_zone');
            $connection->executeStatement("SET time_zone = '+00:00'");
        }
        try {
            $row = $connection->fetchAssociative('SELECT * FROM glpi_documents_items WHERE id = ?', [$row['id']]);
            if ($row === false) {
                throw new RuntimeException('Frozen Domain document disappeared during inspection.');
            }
            $original = DomainsPluginSnapshot::rawRow($row);
        } finally {
            if ($previous !== null) {
                $connection->executeStatement('SET time_zone = ?', [$previous]);
            }
        }
        foreach ($old as $field) {
            if (($original[$field] ?? null) !== null) {
                throw new RuntimeException('Frozen Domain document has a conflicting owning subject: ' . $row['id'] . '.' . $field);
            }
        }
        if (!in_array($original['is_recursive'], ['0', '1'], true)) {
            throw new RuntimeException('Invalid frozen Domain document recursion flag: ' . $row['id']);
        }
        foreach (['documents_id' => 'glpi_documents', 'entities_id' => 'glpi_entities', 'users_id' => 'glpi_users'] as $field => $table) {
            $id = $original[$field] === null && $field === 'users_id' ? 0 : DomainsPluginSnapshot::integer($original[$field], 'document.' . $row['id'] . '.' . $field, $field === 'documents_id' ? 1 : 0);
            if (($field !== 'users_id' || $id > 0) && !$connection->fetchOne('SELECT 1 FROM ' . $table . ' WHERE id = ?', [$id])) {
                throw new RuntimeException('Missing frozen Domain document parent: ' . $row['id'] . '.' . $field);
            }
        }
        $position = DomainsPluginSnapshot::integer($original['timeline_position'], 'document.timeline');
        if ($position > 32767) {
            throw new RuntimeException('Unsupported frozen Domain document timeline: ' . $row['id']);
        }
        foreach (['date_mod', 'date_creation', 'date'] as $field) {
            if ($original[$field] !== null) {
                $this->date($connection, substr($original[$field], 0, 19), 'document.' . $row['id'] . '.' . $field, true);
            }
        }
        return ['id' => DomainsPluginSnapshot::integer($original['id'], 'document.id', 1), 'domain_id' => $domain, 'original' => $original];
    }

    private function incomingDocumentReferences(Connection $connection): void
    {
        foreach (IdentifierColumns::history()['relations'] as $table => $relations) {
            if (in_array('glpi_documents_items', $relations, true)) {
                throw new RuntimeException('Frozen schema has an unsupported incoming document binding owner: ' . $table);
            }
        }
        $incoming = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? $connection->fetchAllAssociative("SELECT TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='glpi_documents_items'")
            : $connection->fetchAllAssociative("SELECT conrelid::regclass::text AS table_name, conname AS constraint_name, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE contype='f' AND confrelid=to_regclass('glpi_documents_items')");
        if ($incoming) {
            throw new RuntimeException('Frozen Domain document deferral refuses incoming foreign keys: ' . json_encode($incoming, JSON_THROW_ON_ERROR));
        }
    }

    private function transactionalTables(Connection $connection, array $tables): void
    {
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        $engines = $connection->fetchAllKeyValue('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
        foreach ($tables as $table) {
            if (strcasecmp($engines[$table] ?? '', 'InnoDB') !== 0) {
                throw new RuntimeException('Frozen Domains prerequisite requires transactional core storage before any data writes: ' . $table . ' must use InnoDB.');
            }
        }
    }

    private function lockSource(Connection $connection, array $plan): void
    {
        if ($plan['version'] === self::RECEIPT) {
            foreach (array_keys(DomainsPluginSnapshot::definition()['source']) as $table) {
                $connection->fetchFirstColumn('SELECT id FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id FOR UPDATE');
            }
        }
        foreach ($plan['receipt']['deferred_documents'] as $row) {
            $connection->fetchOne('SELECT id FROM glpi_documents_items WHERE id = ? FOR UPDATE', [$row['id']]);
        }
    }

    private function remap(Connection $connection, array $plan): void
    {
        foreach ($plan['records'] as $record) {
            $connection->insert($record['table'], $record['values'], $record['types']);
        }
        foreach ($plan['updates'] as $record) {
            $connection->update($record['table'], $record['values'], ['id' => $record['id']]);
        }
        foreach ($plan['policy']['updates'] as $record) {
            $connection->update($record['table'], $record['values'], ['id' => $record['id']]);
        }
        foreach ($plan['policy']['grants'] as $grant) {
            $id = $grant['id'];
            unset($grant['id']);
            $id === null ? $connection->insert('glpi_profilerights', $grant) : $connection->update('glpi_profilerights', $grant, ['id' => $id]);
        }
        foreach ($plan['receipt']['deferred_documents'] as $row) {
            if ($connection->delete('glpi_documents_items', ['id' => $row['id']]) !== 1) {
                throw new RuntimeException('Frozen Domain document changed during deferral: ' . $row['id']);
            }
        }
    }

    private function classValues(string $table, array $row, string $target): array
    {
        return match ($table) {
            'glpi_displaypreferences' => $this->display($row),
            'glpi_savedsearches' => $this->savedSearch($row, $target),
            'glpi_notifications', 'glpi_notificationtemplates', 'glpi_links_itemtypes' => $target === 'Domain' ? [] : throw new RuntimeException('Unsupported frozen DomainType class binding: ' . $table . '.' . $row['id']),
            'glpi_fieldblacklists' => ['field' => $this->property($row['field'])],
            'glpi_fieldunicities' => ['fields' => json_encode(array_map($this->property(...), DomainsPluginPolicy::array((string)($row['fields'] ?? ''), 'unique.' . $row['id'])), JSON_THROW_ON_ERROR)],
            default => throw new RuntimeException('Unsupported frozen Domains class-only role: ' . $table . '.' . $row['id']),
        };
    }

    private function property(string $field): string
    {
        if ($field === 'plugin_domains_domaintypes_id') {
            return 'domaintypes_id';
        }
        if (!in_array($field, ['name', 'entities_id', 'is_recursive', 'date_creation', 'date_expiration', 'users_id_tech', 'groups_id_tech', 'suppliers_id', 'comment', 'others', 'is_helpdesk_visible', 'date_mod', 'is_deleted'], true)) {
            throw new RuntimeException('Unsupported frozen Domains source property: ' . $field);
        }
        return $field;
    }

    private function display(array $row): array
    {
        $this->searchField($row['num']);
        return [];
    }

    private function searchField(mixed $field): void
    {
        if (!is_scalar($field) || !in_array((string)$field, ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '18', '30', '80', '81', 'all', 'view'], true)) {
            throw new RuntimeException('Unsupported frozen Domains search option.');
        }
    }

    private function savedSearch(array $row, string $target): array
    {
        if ($target !== 'Domain' || (int)$row['type'] !== 1) {
            throw new RuntimeException('Unsupported frozen Domains saved search: ' . $row['id']);
        }
        $query = [];
        parse_str((string)($row['query'] ?? ''), $query);
        if (!$query || ($query['itemtype'] ?? 'PluginDomainsDomain') !== 'PluginDomainsDomain' || !empty($query['metacriteria'])) {
            throw new RuntimeException('Invalid frozen Domains saved search: ' . $row['id']);
        }
        $this->criteria($query['criteria'] ?? []);
        if (isset($query['sort'])) {
            $this->searchField($query['sort']);
        }
        $query['itemtype'] = 'Domain';
        return ['query' => http_build_query($query), 'path' => 'front/domain.php'];
    }

    private function criteria(array $criteria): void
    {
        foreach ($criteria as $criterion) {
            if (!is_array($criterion)) {
                throw new RuntimeException('Invalid frozen Domains saved criterion.');
            }
            if (isset($criterion['criteria'])) {
                $this->criteria($criterion['criteria']);
            } elseif (isset($criterion['field'])) {
                $this->searchField($criterion['field']);
            } else {
                throw new RuntimeException('Missing frozen Domains search field.');
            }
        }
    }

    private function verifyReceipt(Connection $connection, array $receipt, array $bindings): void
    {
        if (($receipt['complete'] ?? false) !== true || ($receipt['format'] ?? null) !== DomainsPluginSnapshot::FORMAT) {
            throw new RuntimeException('Unrecognized completed frozen Domains receipt.');
        }
        $source = DomainsPluginSnapshot::read($connection);
        if (($receipt['fingerprint'] ?? null) !== DomainsPluginSnapshot::fingerprint($source)) {
            throw new RuntimeException('Frozen Domains source changed after completed adoption/import; explicit reconciliation is required.');
        }
        $counts = ['types' => count($source['glpi_plugin_domains_domaintypes']), 'domains' => count($source['glpi_plugin_domains_domains']), 'items' => count($source['glpi_plugin_domains_domains_items']), 'configs' => count($source['glpi_plugin_domains_configs'])];
        if (($receipt['counts'] ?? null) !== $counts) {
            throw new RuntimeException('Frozen Domains receipt counts differ from its source.');
        }
        $known = [];
        foreach ($receipt['retained_bindings'] ?? [] as $binding) {
            $known[$binding['table']][$binding['id']][$binding['field']] = true;
        }
        foreach ($bindings as $binding) {
            if (!isset($known[$binding['table']][$binding['row']['id']][$binding['field']])) {
                throw new RuntimeException('New frozen Domains source identity after completed adoption: ' . $binding['table'] . '.' . $binding['row']['id'] . '.' . $binding['field']);
            }
        }
        $known = [];
        foreach ($receipt['retained_source_rights'] ?? [] as $right) {
            $known[$right['id']] = $right;
        }
        foreach ($connection->fetchAllAssociative('SELECT id, profiles_id, name, rights FROM glpi_profilerights WHERE name IN (?, ?, ?) ORDER BY id', ['plugin_domains', 'plugin_domains_dropdown', 'plugin_domains_open_ticket']) as $right) {
            $right = ['id' => (int)$right['id'], 'profiles_id' => (int)$right['profiles_id'], 'name' => $right['name'], 'rights' => (int)$right['rights']];
            if (($known[$right['id']] ?? null) !== $right) {
                throw new RuntimeException('Changed or new frozen Domains source grant after completed adoption: ' . $right['id']);
            }
        }
        foreach ($connection->fetchAllAssociative('SELECT id, helpdesk_item_type FROM glpi_profiles ORDER BY id') as $profile) {
            $values = DomainsPluginPolicy::array((string)($profile['helpdesk_item_type'] ?? ''), 'profile.' . $profile['id']);
            array_walk_recursive($values, static function ($value) use ($profile): void {
                if (is_string($value) && strncasecmp(trim($value), 'PluginDomains', 13) === 0) {
                    throw new RuntimeException('New frozen Domains helpdesk binding after committed adoption: glpi_profiles.' . $profile['id']);
                }
            });
        }
    }

    private function scoped(Connection $connection, int $entity, int $owner, bool $recursive, string $field): void
    {
        if (!$this->available($connection, $entity, $owner, $recursive, $field)) {
            throw new RuntimeException('Frozen Domains scoped owner is unavailable: ' . $field);
        }
    }

    private function available(Connection $connection, int $entity, int $owner, bool $recursive, string $field): bool
    {
        if ($entity === $owner) {
            return true;
        }
        if ($recursive) {
            $seen = [];
            while ($entity !== 0) {
                if (isset($seen[$entity])) {
                    throw new RuntimeException('Cyclic frozen Domains entity scope: ' . $field);
                }
                $seen[$entity] = true;
                $parent = $connection->fetchOne('SELECT entities_id FROM glpi_entities WHERE id = ?', [$entity]);
                if ($parent === false || $parent === null) {
                    break;
                }
                $entity = (int)$parent;
                if ($entity === $owner) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Frozen public CommonDBRelation policy, distinct from one-way dropdown ownership. */
    private function coherent(Connection $connection, ?array $first, ?array $second, string $field): void
    {
        if ($first === null || $second === null
            || $this->available($connection, $first['entity'], $second['entity'], $second['recursive'], $field)
            || $this->available($connection, $second['entity'], $first['entity'], $first['recursive'], $field)) {
            return;
        }
        throw new RuntimeException('Frozen Domains relationship entity incoherence: ' . $field . '; preserve same-entity or a recursive ancestor endpoint before adoption.');
    }

    private function coreScope(Connection $connection, array $definition, int $id): ?array
    {
        $row = $connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($definition['table']) . ' WHERE id = ?', [$id]);
        if (!$row) {
            throw new RuntimeException('Missing frozen Domains relationship owner: ' . $definition['table'] . '.' . $id);
        }
        if ($definition['entity_column'] === null) {
            return null; // Non-scoped public objects do not acquire an invented entity owner.
        }
        $recursive = $definition['recursive_column'];
        if (!array_key_exists($definition['entity_column'], $row) || ($recursive !== null && !array_key_exists($recursive, $row))) {
            throw new RuntimeException('Unsupported frozen Domains scope layout: ' . $definition['table']);
        }
        return ['entity' => DomainsPluginSnapshot::integer($row[$definition['entity_column']], 'scope.' . $definition['table']),
            'recursive' => $definition['always_recursive'] || ($recursive !== null && DomainsPluginSnapshot::flag(DomainsPluginSnapshot::rawRow($row)[$recursive], 'scope.' . $definition['table'] . '.recursion'))];
    }

    private function impactScope(Connection $connection, string $kind, int $id, array $incoming): ?array
    {
        if ($kind === 'PluginDomainsDomain') {
            return $incoming[$id] ?? throw new RuntimeException('Missing frozen Domains impact subject: ' . $id);
        }
        $definition = DomainsPluginSnapshot::definition()['impact_kinds'][$kind] ?? throw new RuntimeException('Unsupported frozen Domains impact kind: ' . $kind);
        return $this->coreScope($connection, $definition, $id);
    }

    private function registration(Connection $connection): ?array
    {
        $rows = $connection->fetchAllAssociative("SELECT id, directory, name, version, state, author, homepage, license FROM glpi_plugins WHERE LOWER(TRIM(directory)) = ? ORDER BY id", ['domains']);
        if (count($rows) > 1) {
            throw new RuntimeException('Ambiguous frozen Domains source plugin registrations.');
        }
        if (!$rows) {
            return null;
        }
        $row = $rows[0];
        if ($row['directory'] !== 'domains' || in_array((int)$row['state'], [1, 3], true)) {
            throw new RuntimeException('Frozen Domains adoption requires the source domains plugin inactive with its exact directory spelling. Deactivate it with the compatible historical application before maintenance/export and keep source/application writers stopped. Its record and other plugins are preserved; uninstall hooks must not run.');
        }
        $row['id'] = (int)$row['id'];
        $row['state'] = (int)$row['state'];
        return $row;
    }
}
