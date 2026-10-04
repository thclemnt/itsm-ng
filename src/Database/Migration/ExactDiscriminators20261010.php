<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\CheckConstraintSupport;

/** Frozen byte-exact subject identities; application properties own current policy. */
final class ExactDiscriminators20261010
{
    public const VERSION = '20261010_exact_subject_discriminators';

    public static function definitions(): array
    {
        static $snapshot;
        return $snapshot ??= json_decode(file_get_contents(__DIR__ . '/history/20261010-exact-subject-discriminators.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Audit every table before writing a receipt or issuing this migration's DDL. */
    public function plan(Connection $connection, bool $preAdoption = false): array
    {
        $plan = $this->inspectPlan($connection, $preAdoption);
        foreach ($plan['tables'] as &$table) {
            // Preservation contains arbitrary existing comments/index options,
            // not executable preview SQL. Keep it solely in the private journal.
            unset($table['preservation']);
        }
        unset($table);
        return $plan;
    }

    private function inspectPlan(Connection $connection, bool $preAdoption = false): array
    {
        CheckConstraintSupport::assertSupported($connection);
        $state = Ledger::state($connection, self::VERSION);
        if (($state['complete'] ?? false) === true) {
            return ['complete' => true, 'tables' => [], 'deferred' => []];
        }
        if ($state !== null && (($state['complete'] ?? null) !== false || !is_int($state['next'] ?? null)
            || $state['next'] < 0 || $state['next'] > count(self::definitions()['tables'])
            || !is_array($state['preservation'] ?? null)
            || array_keys($state['preservation']) !== array_keys(self::definitions()['tables'])
            || array_filter($state['preservation'], static fn ($entry): bool => !is_array($entry))
            || !is_array($state['policy'] ?? null)
            || array_keys($state['policy']) !== array_slice(array_keys(self::definitions()['tables']), 0, $state['next'])
            || array_filter($state['policy'], static fn ($entry): bool => !is_array($entry)))) {
            throw new \RuntimeException('Invalid exact subject journal; inspect the original receipt and native schema before retrying.');
        }
        $states = Ledger::states($connection);
        // Existing adoption records inherited baseline/seeds only after all
        // canonical phases converge. Those markers are not replay prerequisites.
        $prerequisites = array_diff(self::definitions()['prerequisites'], self::definitions()['adoption_markers']);
        $priorPending = array_filter($prerequisites, static fn ($version) => ($states[$version]['complete'] ?? false) !== true);
        if ($priorPending && !$preAdoption) {
            throw new \RuntimeException('Complete the preceding canonical migration history before exact subject adoption.');
        }
        $platform = $connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;
        $quote = $platform->quoteIdentifier(...);
        $schema = (string)$connection->fetchOne($mysql ? 'SELECT DATABASE()' : 'SELECT current_schema()');
        $manager = $connection->createSchemaManager();
        $catalog = $mysql ? BooleanDomainSchema::catalog($connection) : null;
        $incomingSnapshots = $mysql ? IncomingProjectionReferences::mysqlSnapshots($connection, array_keys(self::definitions()['tables'])) : null;
        $incoming = new IncomingProjectionReferences($connection);
        $tables = $deferred = $problems = [];
        foreach (self::definitions()['tables'] as $table => $definition) {
            $actual = $manager->introspectTable($table);
            if (!$actual->hasColumn($definition['column']) || !$actual->hasColumn($definition['discriminator'])) {
                $problems[] = 'Missing subject identity columns: ' . $table;
                continue;
            }
            $native = $mysql
                ? $connection->fetchAssociative('SELECT EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?', [$schema, $table, $definition['column']])
                : $connection->fetchAssociative('SELECT a.attgenerated AS generated FROM pg_catalog.pg_attribute a WHERE a.attrelid=to_regclass(?) AND a.attname=? AND NOT a.attisdropped', [$quote($table), $definition['column']]);
            $generated = $mysql ? !empty($native['GENERATION_EXPRESSION']) : ($native['generated'] ?? '') !== '';
            $missing = array_filter($definition['branches'], static fn ($branch) => !$actual->hasColumn($branch['column']));
            $legacy = $preAdoption && $priorPending && !$generated;
            if (($missing || !$generated) && !$legacy) {
                $problems[] = 'Incomplete canonical owning subject columns/projection: ' . $table;
                continue;
            }
            // A partially adopted ordinary legacy identity is audited against
            // every canonical column that already exists, never ignored wholesale.
            $valid = self::validSql($connection, $definition, $actual, $legacy, true);
            $count = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($table) . ' source_subject WHERE NOT COALESCE((' . $valid . '), FALSE)');
            if ($count > 0) {
                $samples = $connection->fetchAllAssociative('SELECT ' . $quote('id') . ', ' . $quote($definition['discriminator']) . ', ' . $quote($definition['column'])
                    . ' FROM ' . $quote($table) . ' source_subject WHERE NOT COALESCE((' . $valid . '), FALSE) ORDER BY ' . $quote('id') . ' LIMIT 5');
                $problems[] = 'Invalid exact subject data: ' . $table . ' (' . $count . ' rows); samples: ' . json_encode($samples, JSON_THROW_ON_ERROR)
                    . '. Correct the source assignment explicitly; no spelling or identifier is rewritten.';
            }
            if ($priorPending) {
                $deferred[] = $table;
                continue;
            }
            foreach ($definition['branches'] as $branch) {
                $owned = false;
                foreach ($actual->getForeignKeys() as $foreign) {
                    if ($foreign->getLocalColumns() !== [$branch['column']] || $foreign->getForeignColumns() !== ['id']) {
                        continue;
                    }
                    $targetMatches = $mysql ? $foreign->getForeignTableName() === $branch['target']
                        : in_array($connection->fetchOne(
                            'SELECT to_regclass(?) = to_regclass(?)',
                            [$foreign->getReferencedTableName()->toSQL($platform), $quote($branch['target'])]
                        ), [true, 1, '1', 't'], true);
                    $owned = $owned || $targetMatches;
                }
                if (!$owned) {
                    $problems[] = 'Missing or conflicting native subject FK: ' . $table . '.' . $branch['column'];
                }
            }
            foreach ($actual->getForeignKeys() as $foreign) {
                if (in_array($definition['column'], $foreign->getLocalColumns(), true)) {
                    $problems[] = 'Outgoing compatibility identity FK requires an explicit ownership migration: ' . $table . '.' . $foreign->getName();
                }
            }
            if (!$mysql) {
                $check = $connection->fetchAssociative('SELECT convalidated FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND conname=? AND contype=?', [$quote($table), $definition['constraint'], 'c']);
                if (!is_array($check) || !in_array($check['convalidated'], [true, 1, '1', 't'], true)) {
                    $problems[] = 'Missing or unvalidated owned subject CHECK: ' . $table . '.' . $definition['constraint'];
                }
                $deterministic = $connection->fetchOne('SELECT c.collisdeterministic FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_collation c ON c.oid=a.attcollation WHERE a.attrelid=to_regclass(?) AND a.attname=?', [$quote($table), $definition['discriminator']]);
                if (!in_array($deterministic, [true, 1, '1', 't'], true)) {
                    $problems[] = 'Exact subject policy requires deterministic PostgreSQL discriminator collation: ' . $table;
                }
            }
            $preserve = self::preservation($connection, $actual, $catalog['checks'] ?? null, $incomingSnapshots[$table] ?? null);
            if (isset($state['preservation'][$table]) && $state['preservation'][$table] !== $preserve) {
                $problems[] = 'Exact subject retry ownership/index/comment changed: ' . $table;
            }
            if (isset($state['policy'][$table]) && $state['policy'][$table] !== self::nativePolicy($connection, $table, $definition, $catalog)) {
                $problems[] = 'Exact subject checkpoint native policy changed: ' . $table
                    . '. Inspect the journal and native schema; processed policy is never silently overwritten.';
            }
            $statement = null;
            if ($mysql) {
                $check = $catalog['checks'][$table][$definition['constraint']] ?? null;
                if ($check === null || $check['enforced'] !== 'YES') {
                    $problems[] = 'Missing or unenforced owned subject CHECK: ' . $table . '.' . $definition['constraint'];
                    continue;
                }
                $declaration = self::projectionSql($connection, $definition);
                $comment = $preserve['comment'];
                if ($comment !== '') {
                    $declaration .= ' ' . $platform->getInlineColumnCommentSQL($comment);
                }
                // One native ALTER retains the column, all its indexes and real
                // incoming FKs. No constraint disabling or projection DROP gap.
                $statement = 'ALTER TABLE ' . $quote($table) . ' DROP ' . ($platform instanceof MariaDBPlatform ? 'CONSTRAINT ' : 'CHECK ')
                    . $quote($definition['constraint']) . ', MODIFY COLUMN ' . $quote($definition['column']) . ' ' . $declaration
                    . ', ADD CONSTRAINT ' . $quote($definition['constraint']) . ' CHECK (' . self::validSql($connection, $definition, $actual, false, false) . ')'
                    . ($platform instanceof MySQLPlatform ? ' ENFORCED' : '');
            } else {
                // Install the frozen owning predicate explicitly. PostgreSQL
                // keeps its already exact deterministic generated expression.
                $statement = 'ALTER TABLE ' . $quote($table) . ' DROP CONSTRAINT ' . $quote($definition['constraint'])
                    . ', ADD CONSTRAINT ' . $quote($definition['constraint']) . ' CHECK (' . self::validSql($connection, $definition, $actual, false, false) . ')';
            }
            $tables[$table] = ['sql' => $statement, 'preservation' => $preserve,
                'incoming_projection_references' => $incoming->has($schema, $table)];
        }
        if ($problems) {
            throw new \RuntimeException("Exact subject preflight failed before DDL or receipt:\n" . implode("\n", $problems));
        }
        return ['complete' => false, 'tables' => $tables, 'deferred' => $deferred];
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        if ((Ledger::state($connection, self::VERSION)['complete'] ?? false) === true) {
            return;
        }
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        if ($mysql && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL exact subject DDL must run outside an application transaction.');
        }
        $apply = function () use ($connection, $progress, $mysql): void {
            $plan = $this->inspectPlan($connection);
            $state = Ledger::state($connection, self::VERSION) ?? ['complete' => false, 'next' => 0,
                'preservation' => array_map(static fn ($entry) => $entry['preservation'], $plan['tables']), 'policy' => []];
            Ledger::save($connection, self::VERSION, $state);
            foreach (array_keys($plan['tables']) as $offset => $table) {
                if ($offset < $state['next']) {
                    continue;
                }
                $entry = $plan['tables'][$table];
                if ($entry['sql'] !== null) {
                    $connection->executeStatement($entry['sql']);
                }
                if (self::preservation($connection, $connection->createSchemaManager()->introspectTable($table)) !== $entry['preservation']) {
                    throw new \RuntimeException('Exact subject DDL changed ownership/index/comment: ' . $table);
                }
                // Native output is captured only after the authoritative frozen
                // ALTER succeeds. This journal cache is not a second declaration
                // or a provider-specific predicate-normalization heuristic.
                $policy = self::nativePolicy($connection, $table, self::definitions()['tables'][$table]);
                // A callback interruption before checkpoint retries the same
                // idempotent table ALTER, including its preserved comment/indexes.
                $progress && $progress('Exact subject: ' . $table);
                if (self::nativePolicy($connection, $table, self::definitions()['tables'][$table]) !== $policy) {
                    throw new \RuntimeException('Exact subject native policy changed before checkpoint: ' . $table);
                }
                $state['policy'][$table] = $policy;
                $state['next'] = $offset + 1;
                Ledger::save($connection, self::VERSION, $state);
            }
            $remaining = $this->inspectPlan($connection);
            if ($remaining['deferred']) {
                throw new \RuntimeException('Exact subject history remained deferred; completion was not recorded.');
            }
            Ledger::save($connection, self::VERSION, ['complete' => true]);
        };
        $mysql ? $apply() : $connection->transactional($apply);
    }

    public static function projectionSql(Connection $connection, array $definition): string
    {
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $discriminator = $quote($definition['discriminator']);
        if ($platform instanceof AbstractMySQLPlatform) {
            $discriminator = 'CAST(' . $discriminator . ' AS BINARY)';
        }
        $cases = [];
        foreach ($definition['branches'] as $kind => $branch) {
            $cases[] = 'WHEN ' . $discriminator . ' = ' . $platform->quoteStringLiteral($kind) . ' THEN ' . $quote($branch['column']);
        }
        return 'BIGINT GENERATED ALWAYS AS (CASE ' . implode(' ', $cases) . ' ELSE '
            . ($definition['empty_value'] === null ? 'NULL' : $definition['empty_value']) . ' END) STORED';
    }

    private static function validSql(Connection $connection, array $definition, \Doctrine\DBAL\Schema\Table $actual, bool $legacy, bool $audit): string
    {
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $field = static fn (string $column): string => ($audit ? 'source_subject.' : '') . $quote($column);
        $kindColumn = $field($definition['discriminator']);
        $kind = $platform instanceof AbstractMySQLPlatform ? 'CAST(' . $kindColumn . ' AS BINARY)' : $kindColumn;
        $key = $field($definition['column']);
        $columns = array_unique(array_column($definition['branches'], 'column'));
        $branches = [];
        foreach ($definition['branches'] as $value => $branch) {
            $selected = $legacy ? $key : $field($branch['column']);
            $conditions = [$kindColumn . ' IS NOT NULL', $kind . ' = ' . $platform->quoteStringLiteral($value),
                $selected . ' IS NOT NULL', $selected . ' >= ' . $branch['minimum']];
            foreach ($columns as $column) {
                if (!$actual->hasColumn($column)) {
                    continue;
                }
                if ($column !== $branch['column']) {
                    $conditions[] = $field($column) . ' IS NULL';
                } elseif ($legacy) {
                    $conditions[] = '(' . $field($column) . ' IS NULL OR ' . $field($column) . ' = ' . $key . ')';
                }
            }
            if ($audit) {
                $conditions[] = $key . ' = ' . $selected;
                $conditions[] = 'EXISTS (SELECT 1 FROM ' . $quote($branch['target']) . ' subject_target WHERE subject_target.' . $quote('id') . ' = ' . $selected . ')';
            }
            $branches[] = '(' . implode(' AND ', $conditions) . ')';
        }
        if ($definition['empty_value'] !== null) {
            $empty = [$legacy ? '(' . $kindColumn . ' IS NULL OR ' . $kind . " = '')" : $kindColumn . ' IS NULL'];
            foreach ($columns as $column) {
                if ($actual->hasColumn($column)) {
                    $empty[] = $field($column) . ' IS NULL';
                }
            }
            foreach ($definition['empty_required_null'] as $column) {
                $empty[] = $field($column) . ' IS NULL';
            }
            if ($audit) {
                $empty[] = $legacy ? '(' . $key . ' IS NULL OR ' . $key . ' = ' . $definition['empty_value'] . ')' : $key . ' = ' . $definition['empty_value'];
            }
            $branches[] = '(' . implode(' AND ', $empty) . ')';
        }
        return implode(' OR ', $branches);
    }

    private static function preservation(Connection $connection, \Doctrine\DBAL\Schema\Table $table, ?array $checks = null, ?array $incomingSnapshot = null): array
    {
        $indexes = $foreignKeys = [];
        foreach ($table->getIndexes() as $index) {
            $indexes[$index->getName()] = ['columns' => $index->getColumns(), 'unique' => $index->isUnique(), 'primary' => $index->isPrimary(),
                'flags' => $index->getFlags(), 'options' => $index->getOptions()];
        }
        foreach ($table->getForeignKeys() as $foreign) {
            $foreignKeys[$foreign->getName()] = ['columns' => $foreign->getLocalColumns(), 'target' => $foreign->getForeignTableName(),
                'target_columns' => $foreign->getForeignColumns(), 'options' => $foreign->getOptions()];
        }
        ksort($indexes);
        ksort($foreignKeys);
        $native = [];
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $native['columns'] = $connection->fetchAllAssociative('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT, DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table->getName()]);
            foreach ($native['columns'] as &$column) {
                if ($column['COLUMN_NAME'] === 'items_id') {
                    // Only this expression is intentionally changed; physical
                    // width/nullability/default/comment/generation storage stay.
                    unset($column['GENERATION_EXPRESSION']);
                }
            }
            unset($column);
            $native['table'] = $connection->fetchAssociative('SELECT ENGINE, AUTO_INCREMENT, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table->getName()]);
            $checks ??= BooleanDomainSchema::checks($connection, $table->getName());
            $native['other_checks'] = $checks[$table->getName()] ?? [];
            unset($native['other_checks'][self::definitions()['tables'][$table->getName()]['constraint']]);
            // Incoming references remain attached to this same column. Do not
            // omit other schemas or silently discard a supported custom FK.
            $native['incoming'] = $incomingSnapshot ?? IncomingProjectionReferences::mysqlReferences($connection, $table->getName());
        } else {
            // Relation OIDs keep all referencing namespaces and complete
            // composite FK definitions, even when constraint names repeat.
            $native['incoming'] = $connection->fetchAllAssociative('SELECT n.nspname AS schema_name, child.relname AS table_name, f.conname AS constraint_name, f.convalidated, pg_get_constraintdef(f.oid) AS definition FROM pg_catalog.pg_constraint f JOIN pg_catalog.pg_class child ON child.oid=f.conrelid JOIN pg_catalog.pg_namespace n ON n.oid=child.relnamespace WHERE f.contype=? AND f.confrelid=to_regclass(?) AND EXISTS (SELECT 1 FROM pg_catalog.pg_attribute a WHERE a.attrelid=f.confrelid AND a.attname=? AND a.attnum=ANY(f.confkey)) ORDER BY n.nspname, child.relname, f.conname', ['f', $table->getQuotedName($connection->getDatabasePlatform()), 'items_id']);
        }
        return ['comment' => (string)$table->getColumn('items_id')->getComment(), 'indexes' => $indexes, 'foreign_keys' => $foreignKeys, 'native' => $native];
    }

    private static function nativePolicy(Connection $connection, string $table, array $definition, ?array $catalog = null): array
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $checks = $catalog === null ? BooleanDomainSchema::checks($connection, $table) : $catalog['checks'];
            return ['projection' => $connection->fetchOne('SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $definition['column']]),
                'check' => $checks[$table][$definition['constraint']] ?? null];
        }
        $quote = $platform->quoteIdentifier(...);
        return ['projection' => $connection->fetchOne('SELECT pg_get_expr(d.adbin,d.adrelid) FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=to_regclass(?) AND a.attname=? AND NOT a.attisdropped', [$quote($table), $definition['column']]),
            'check' => $connection->fetchAssociative('SELECT pg_get_constraintdef(oid) AS definition, convalidated FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND conname=? AND contype=?', [$quote($table), $definition['constraint'], 'c'])];
    }
}
