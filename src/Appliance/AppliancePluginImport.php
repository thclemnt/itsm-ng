<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Appliance;

use DateTimeImmutable;
use DBAdapter;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\Orm;
use itsmng\Database\PluginImportMutation;
use itsmng\Database\ReferenceValues;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\SchemaCheck;
use itsmng\Database\SequenceSynchronizer;
use Profile;
use RuntimeException;
use Throwable;
use Toolbox;

use function importArrayFromDB;

/** Import an appliance aggregate without replacing unrelated core records or replaying it. */
final class AppliancePluginImport
{
    /** Elective import provenance in the existing ledger; not a core schema migration. */
    public const RECEIPT = '20261005_appliances_plugin_import_v1';

    public function __construct(private DBAdapter $database)
    {
    }

    public function plan(): ApplianceImportPlan
    {
        $connection = $this->database->getDoctrineConnection();
        foreach (History::versions() as $version) {
            if ((Ledger::state($connection, $version)['complete'] ?? false) !== true) {
                throw new RuntimeException('Appliance import requires completed canonical history; run db:migrate for a supported core upgrade. '
                    . 'Current ORM import cannot convert a legacy schema: plugin identities rejected by adoption need a compatible historical migration before switching source. Pending: ' . $version);
            }
        }
        $differences = (new SchemaCheck())->differences($connection);
        if ($differences) {
            throw new RuntimeException('Appliance import requires the canonical core schema: ' . implode('; ', array_slice($differences, 0, 5)));
        }
        $snapshot = (new PluginApplianceSource($connection))->read();
        $fingerprint = $snapshot->fingerprint();
        $receipt = Ledger::state($connection, self::RECEIPT);
        if ($receipt !== null) {
            if (($receipt['complete'] ?? false) !== true || ($receipt['fingerprint'] ?? null) !== $fingerprint) {
                throw new RuntimeException('Appliance plugin source differs from its completed import receipt; incremental import requires an explicit reconciliation.');
            }
            // Later application edits and purges belong to users, not this historical export.
            return new ApplianceImportPlan($fingerprint, $receipt['counts'], alreadyImported: true);
        }
        PluginImportMutation::assertTransactionalCore($connection, 'Appliance');
        $em = Orm::create($this->database);
        try {
            $records = $incoming = [];
            foreach ($snapshot->records() as [$model, $input]) {
                $table = $model::getTable();
                $class = EntityRegistry::tables()[$table];
                $values = $this->normalize($em, $class, $input);
                $id = $values['id'];
                if (isset($incoming[$table][$id])) {
                    throw new RuntimeException('Duplicate appliance plugin identifier: ' . $table . '.' . $id);
                }
                $incoming[$table][$id] = true;
                $records[] = ['model' => $model, 'class' => $class, 'table' => $table, 'input' => $values + $input, 'values' => $values];
            }
            $uniques = $collations = [];
            $repository = new RecordRepository($em);
            foreach ($records as $record) {
                $metadata = $em->getClassMetadata($record['class']);
                $id = $record['values']['id'];
                if ($em->getRepository($record['class'])->find($id) !== null) {
                    throw new RuntimeException('Appliance import ID collision: ' . $record['table'] . '.' . $id . '; existing core data is not owned by this import.');
                }
                foreach ($metadata->associationMappings as $association) {
                    if (!$association->isToOneOwningSide()) {
                        continue;
                    }
                    $column = $association->joinColumns[0]->name;
                    $targetId = $record['values'][$column] ?? null;
                    if ($targetId === null) {
                        continue;
                    }
                    $target = $em->getClassMetadata($association->targetEntity)->getTableName();
                    if (!isset($incoming[$target][$targetId]) && $em->getRepository($association->targetEntity)->find($targetId) === null) {
                        throw new RuntimeException('Invalid appliance import target: ' . $record['table'] . '.' . $id . '.' . $column . ' -> ' . $target . '.' . $targetId);
                    }
                }
                foreach ($metadata->table['uniqueConstraints'] ?? [] as $name => $constraint) {
                    $criteria = [];
                    foreach ($constraint['columns'] as $column) {
                        $column = trim($column, '`');
                        $value = $record['values'][$column] ?? $record['input'][$column] ?? null;
                        if ($value === null) {
                            continue 2; // Preserve ordinary nullable-unique semantics.
                        }
                        $criteria[$column] = $value;
                    }
                    $key = json_encode($criteria, JSON_THROW_ON_ERROR);
                    $previous = $uniques[$record['table']][$name] ?? [];
                    $duplicate = isset($previous[$key]);
                    if (!$duplicate && $previous && $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                        foreach ($criteria as $column => $value) {
                            if (!array_key_exists($column, $collations[$record['table']] ?? [])) {
                                $collations[$record['table']][$column] = $connection->fetchAssociative(
                                    'SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                                    [$record['table'], $column]
                                );
                            }
                        }
                        // The destination's collation owns equality (including accents and
                        // padding). PHP string equality cannot validate a MySQL unique key.
                        foreach ($previous as $earlier) {
                            $comparisons = $parameters = [];
                            foreach ($criteria as $column => $value) {
                                $definition = $collations[$record['table']][$column];
                                if ($definition['COLLATION_NAME'] !== null) {
                                    $charset = $connection->quoteIdentifier($definition['CHARACTER_SET_NAME']);
                                    $collation = $connection->quoteIdentifier($definition['COLLATION_NAME']);
                                    $expression = 'CONVERT(? USING ' . $charset . ') COLLATE ' . $collation;
                                    $comparisons[] = '(' . $expression . ' = ' . $expression . ')';
                                    array_push($parameters, $value, $earlier[$column]);
                                } elseif ($value !== $earlier[$column]) {
                                    continue 2;
                                }
                            }
                            if (!$comparisons || (int)$connection->fetchOne('SELECT ' . implode(' AND ', $comparisons), $parameters) === 1) {
                                $duplicate = true;
                                break;
                            }
                        }
                    }
                    if ($duplicate || $repository->countMatching($record['table'], $criteria, legacyValues: false)) {
                        throw new RuntimeException('Appliance import unique collision: ' . $record['table'] . '.' . $name . ' ' . $key);
                    }
                    $uniques[$record['table']][$name][$key] = $criteria;
                }
            }
            $bindings = $this->bindings($em, $incoming['glpi_appliances'] ?? []);
            $histories = $em->createQueryBuilder()
                ->select('h')
                ->from(Entity\Log::class, 'h')
                ->where('h.itemtype = :kind OR h.itemtype_link = :kind')
                ->setParameter('kind', PluginApplianceSource::ITEMTYPE)
                ->getQuery()
                ->getArrayResult();
            $audits = [];
            foreach ($histories as $history) {
                $fields = [];
                if ($history['itemtype'] === PluginApplianceSource::ITEMTYPE && isset($incoming['glpi_appliances'][(int)$history['items_id']])) {
                    $fields[] = 'itemtype';
                }
                // Linked roles supply class labels in Log::getHistoryData; their
                // original display values do not become an owning ID association.
                if ($history['itemtype_link'] === PluginApplianceSource::ITEMTYPE) {
                    $fields[] = 'itemtype_link';
                }
                if ($fields) {
                    $audits[] = ['id' => (int)$history['id'], 'fields' => $fields];
                }
            }
            $profiles = [];
            foreach ($em->getRepository(Entity\Profile::class)->findAll() as $profile) {
                $types = importArrayFromDB($profile->helpdesk_item_type);
                if (in_array(PluginApplianceSource::ITEMTYPE, $types, true)) {
                    $profiles[] = $profile->id;
                } elseif ($types === [] && str_contains($profile->helpdesk_item_type ?? '', PluginApplianceSource::ITEMTYPE)) {
                    throw new RuntimeException('Invalid encoded helpdesk types in profile ' . $profile->id);
                }
            }
            return new ApplianceImportPlan($fingerprint, $snapshot->counts(), $records, $bindings, $audits, $profiles);
        } finally {
            $em->clear();
        }
    }

    public function import(?callable $progress = null): ApplianceImportPlan
    {
        if ($this->database !== ($GLOBALS['DB'] ?? null) || $this->database->isSlave()) {
            throw new RuntimeException('Appliance lifecycle import requires the application writable connection.');
        }
        $connection = $this->database->getDoctrineConnection();
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $lock = 'itsmng_appliance_import_' . sha1($connection->getDatabase());
        if (!$postgres && (int)$connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            throw new RuntimeException('Another appliance import is running.');
        }
        global $CFG_GLPI;
        $hadInfocom = array_key_exists('auto_create_infocoms', $CFG_GLPI);
        $infocom = $CFG_GLPI['auto_create_infocoms'] ?? null;
        $failure = null;
        try {
            return PluginImportMutation::run($this->database, function (callable $assertActive, LifecycleModelJournal $journal) use ($connection, $postgres, $progress): ApplianceImportPlan {
                if ($postgres && !in_array($connection->fetchOne("SELECT pg_try_advisory_xact_lock(hashtext('itsmng_appliance_import'))"), [true, 1, '1', 't'], true)) {
                    throw new RuntimeException('Another appliance import is running.');
                }
                // Re-read the source and destination while serialized against other imports.
                $plan = $this->plan();
                if ($plan->alreadyImported) {
                    return $plan;
                }
                $GLOBALS['CFG_GLPI']['auto_create_infocoms'] = false;
                foreach ($plan->records as $record) {
                    $model = new $record['model']();
                    $journal->remember($model);
                    $input = Toolbox::addslashes_deep($record['input']);
                    $input['_no_message'] = true;
                    $created = $model->addWithAssignedIdentifier($record['values']['id'], $input);
                    $assertActive();
                    if ($created !== $record['values']['id']) {
                        throw new RuntimeException('Appliance lifecycle creation failed: ' . $record['table'] . '.' . $record['values']['id']);
                    }
                    $progress && $progress('created', $record['table'], $record['values']['id']);
                    $assertActive();
                }
                $em = Orm::create($this->database);
                try {
                    foreach ($plan->bindings as $binding) {
                        $record = $em->getRepository($binding['class'])->find($binding['id']);
                        if ($record === null || $record->itemtype !== PluginApplianceSource::ITEMTYPE) {
                            throw new RuntimeException('Appliance identity binding changed during import.');
                        }
                        if ($binding['association'] !== null) {
                            foreach (EntityRegistry::discriminatedReferences($binding['table'])['items_id']['selections'] as $kind => $selection) {
                                $property = $binding['class']::referenceAssociation($kind);
                                $record->$property = $property === $binding['association'] ? $em->getReference(Entity\Appliance::class, $binding['subject']) : null;
                            }
                        }
                        $record->itemtype = 'Appliance';
                    }
                    foreach ($plan->audits as $audit) {
                        $history = $em->getRepository(Entity\Log::class)->find($audit['id']);
                        foreach ($audit['fields'] as $field) {
                            if ($history->$field === PluginApplianceSource::ITEMTYPE) {
                                $history->$field = 'Appliance';
                            }
                        }
                    }
                    $em->flush(); // Canonical associations and discriminator change atomically.
                } finally {
                    $em->clear();
                }
                foreach ($plan->profiles as $id) {
                    $profile = new Profile();
                    $journal->remember($profile);
                    $loaded = $profile->getFromDB($id);
                    $assertActive();
                    $updated = $loaded && $profile->replaceHelpdeskItemType(PluginApplianceSource::ITEMTYPE, 'Appliance');
                    $assertActive();
                    if (!$updated) {
                        throw new RuntimeException('Appliance profile adoption failed: ' . $id);
                    }
                }
                $progress && $progress('adopted', 'bindings', count($plan->bindings));
                $assertActive();
                SequenceSynchronizer::synchronize($connection);
                Ledger::save($connection, self::RECEIPT, ['complete' => true, 'fingerprint' => $plan->fingerprint, 'counts' => $plan->counts]);
                $progress && $progress('complete', 'receipt', 1);
                $assertActive();
                return $plan;
            });
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            if ($hadInfocom) {
                $CFG_GLPI['auto_create_infocoms'] = $infocom;
            } else {
                unset($CFG_GLPI['auto_create_infocoms']);
            }
            if (!$postgres) {
                try {
                    $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
                } catch (Throwable $cleanup) {
                    throw $failure === null ? $cleanup : new MutationCleanupFailure(
                        $failure,
                        $cleanup,
                        $failure instanceof MutationCleanupFailure && $failure->rollbackUnproven
                    );
                }
            }
        }
    }

    private function bindings(EntityManager $em, array $appliances): array
    {
        $result = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->name === Entity\Log::class || !$metadata->hasField('itemtype') || !$metadata->hasField('items_id')) {
                continue;
            }
            $rows = $em->createQueryBuilder()
                ->select('b.id, b.items_id')
                ->from($metadata->name, 'b')
                ->where('b.itemtype = :kind')
                ->setParameter('kind', PluginApplianceSource::ITEMTYPE)
                ->getQuery()
                ->getScalarResult();
            foreach ($rows as $row) {
                if ($row['items_id'] === null || !isset($appliances[(int)$row['items_id']])) {
                    throw new RuntimeException('Invalid appliance plugin binding: ' . $metadata->getTableName() . '.' . $row['id'] . '; subject missing from source.');
                }
                $association = null;
                $selections = EntityRegistry::discriminatedReferences($metadata->getTableName())['items_id']['selections'] ?? [];
                if ($selections) {
                    if (!isset($selections['Appliance']) || !is_a($metadata->name, LegacyInput::class, true)) {
                        throw new RuntimeException('Binding does not support a core Appliance subject: ' . $metadata->getTableName());
                    }
                    $association = $metadata->name::referenceAssociation('Appliance');
                }
                $repository = new RecordRepository($em);
                $values = $repository->find($metadata->getTableName(), 'id', (int)$row['id']);
                $values['itemtype'] = 'Appliance';
                foreach ($metadata->table['uniqueConstraints'] ?? [] as $name => $constraint) {
                    $criteria = array_intersect_key($values, array_flip(array_map(static fn ($column) => trim($column, '`'), $constraint['columns'])));
                    if (!in_array(null, $criteria, true) && $repository->countMatching($metadata->getTableName(), $criteria, legacyValues: false)) {
                        throw new RuntimeException('Appliance binding adoption collides with existing core ownership: ' . $metadata->getTableName() . '.' . $name);
                    }
                }
                $result[] = ['class' => $metadata->name, 'table' => $metadata->getTableName(), 'id' => (int)$row['id'],
                    'subject' => (int)$row['items_id'], 'association' => $association];
            }
        }
        return $result;
    }

    private function normalize(EntityManager $em, string $class, array $input): array
    {
        $metadata = $em->getClassMetadata($class);
        $values = ReferenceValues::normalizeLegacy($metadata->getTableName(), $input);
        $entity = new $class();
        if ($entity instanceof LegacyInput) {
            $values = $entity->normalizeInput($values);
        }
        $associations = [];
        foreach ($metadata->associationMappings as $association) {
            if ($association->isToOneOwningSide()) {
                $join = $association->joinColumns[0];
                $associations[$join->name] = $join;
            }
        }
        foreach ($values as $column => &$value) {
            if (isset($associations[$column])) {
                if ($value === null && $associations[$column]->nullable) {
                    continue;
                }
                if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 0) {
                    throw new RuntimeException('Invalid appliance import reference: ' . $metadata->getTableName() . '.' . $column);
                }
                $value = (int)$value;
                continue;
            }
            $field = $metadata->getFieldName($column);
            $mapping = $metadata->getFieldMapping($field);
            if ($value === null) {
                if (!$mapping->nullable) {
                    throw new RuntimeException('NULL appliance import field: ' . $metadata->getTableName() . '.' . $column);
                }
                continue;
            }
            if ($mapping->type === 'boolean') {
                if (!in_array($value, [false, true, 0, 1, '0', '1', 't', 'f'], true)) {
                    throw new RuntimeException('Invalid appliance import boolean: ' . $metadata->getTableName() . '.' . $column);
                }
                $value = in_array($value, [true, 1, '1', 't'], true);
            } elseif (in_array($mapping->type, ['integer', 'smallint', 'bigint'], true)) {
                if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || ($column === 'id' && (int)$value <= 0)) {
                    throw new RuntimeException('Invalid appliance import identifier: ' . $metadata->getTableName() . '.' . $column);
                }
                $value = (int)$value;
            } elseif (in_array($mapping->type, ['date', 'datetime', 'datetimetz'], true)) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:[+-]\d{2}(?::?\d{2})?)?$/D', (string)$value)) {
                    throw new RuntimeException('Invalid appliance import date: ' . $metadata->getTableName() . '.' . $column);
                }
                $date = new DateTimeImmutable((string)$value);
                if ($date->format('Y-m-d H:i:s') !== substr((string)$value, 0, 19)) {
                    throw new RuntimeException('Invalid appliance import calendar date: ' . $metadata->getTableName() . '.' . $column);
                }
                $connection = $em->getConnection();
                if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
                    && $connection->fetchOne(
                        'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                        [$metadata->getTableName(), $column]
                    ) === 'timestamp') {
                    // Native timestamp ranges vary by server release. Ask the installed
                    // engine instead of imposing an unrelated DATETIME or 32-bit range.
                    $instant = $connection->fetchOne('SELECT UNIX_TIMESTAMP(?)', [$date->format('Y-m-d H:i:s')]);
                    if ($instant === null || (float)$instant <= 0) {
                        throw new RuntimeException('Appliance import date exceeds native MySQL TIMESTAMP range: ' . $metadata->getTableName() . '.' . $column);
                    }
                }
                $value = $date->format('Y-m-d H:i:sP');
            } else {
                if (!is_scalar($value) || ($mapping->length !== null && mb_strlen((string)$value) > $mapping->length)) {
                    throw new RuntimeException('Invalid appliance import text: ' . $metadata->getTableName() . '.' . $column);
                }
                $value = (string)$value;
            }
        }
        unset($value);
        foreach ($associations as $column => $join) {
            if (!$join->nullable && !array_key_exists($column, $values)) {
                throw new RuntimeException('Missing appliance import ownership: ' . $metadata->getTableName() . '.' . $column);
            }
        }
        return $values;
    }
}
