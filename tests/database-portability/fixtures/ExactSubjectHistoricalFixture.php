<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\DefaultExpression;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\Migration\ExactDiscriminators20261010;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\LegacyToOrm;

require_once __DIR__ . '/MySQLNativeSubjectDeclaration.php';

/** Disposable native fixture; snapshots real schema/receipt before any alteration. */
final class ExactSubjectHistoricalFixture
{
    private array $original = [];
    private array|false $receipt;
    private bool $detached = false;
    private array $definitions;
    private array $restorationFacts = [];
    private array $restorationTables = [];
    private ?array $originalLedger = null;
    private array $mysqlDeclarations = [];
    private ?string $mysqlDeclarationMode = null;

    public function __construct(private Connection $connection, ?array $tables = null, bool $captureTableDeclarations = true, bool $preserveLedger = false)
    {
        if ($connection->getTransactionNestingLevel() !== 0 || History::pendingVersions($connection) !== []) {
            throw new LogicException('An idle complete disposable history is required before historical fixture setup.');
        }
        $definitions = ExactDiscriminators20261010::definitions()['tables'];
        if ($tables !== null && (!array_is_list($tables) || $tables === []
            || count(array_unique($tables, SORT_REGULAR)) !== count($tables)
            || array_filter($tables, static fn ($table): bool => !is_string($table) || !isset($definitions[$table])))) {
            throw new LogicException('Select a nonempty unique scope from the frozen exact-subject declaration.');
        }
        $this->definitions = $tables === null ? $definitions : array_intersect_key($definitions, array_flip($tables));
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $database = $connection->fetchOne($platform instanceof AbstractMySQLPlatform ? 'SELECT DATABASE()' : 'SELECT current_database()');
        if (!is_string($database) || !str_starts_with($database, 'itsm_port_')) {
            throw new LogicException('Historical subject fixture requires a disposable database.');
        }
        $this->receipt = $connection->fetchAssociative('SELECT version, state FROM ' . $quote(LegacyToOrm::LEDGER) . ' WHERE version=?', [ExactDiscriminators20261010::VERSION]);
        if ($this->receipt === false) {
            throw new LogicException('Capture a real completed exact-subject receipt; never synthesize one.');
        }
        if ($preserveLedger) {
            if ($tables === null) {
                throw new LogicException('Complete ledger preservation requires an explicit selected historical scope.');
            }
            $this->originalLedger = $connection->fetchAllAssociative('SELECT version, state FROM ' . $quote(LegacyToOrm::LEDGER) . ' ORDER BY version');
        }
        $catalog = $platform instanceof AbstractMySQLPlatform ? BooleanDomainSchema::catalog($connection) : null;
        if ($platform instanceof MySQLPlatform) {
            $this->mysqlDeclarationMode = (string)$connection->fetchOne('SELECT @@SESSION.sql_mode');
        }
        foreach ($this->definitions as $table => $definition) {
            $comment = (string)$connection->createSchemaManager()->introspectTable($table)->getColumn('items_id')->getComment();
            if ($platform instanceof AbstractMySQLPlatform) {
                $projection = $connection->fetchOne('SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'items_id']);
                $check = $catalog['checks'][$table][$definition['constraint']] ?? null;
                if (!is_string($projection) || $projection === '' || $check === null || $check['enforced'] !== 'YES') {
                    throw new LogicException('Complete enforced native subject schema required before fixture alteration.');
                }
                $this->original[$table] = ['projection' => $projection, 'check' => 'CHECK (' . $check['clause'] . ')', 'comment' => $comment];
            } else {
                $check = $connection->fetchAssociative('SELECT pg_get_constraintdef(oid) AS definition, convalidated FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND conname=? AND contype=?', [$quote($table), $definition['constraint'], 'c']);
                if (!is_array($check) || !in_array($check['convalidated'], [true, 1, '1', 't'], true)) {
                    throw new LogicException('Validated native subject CHECK required before fixture alteration.');
                }
                $nativeComment = $connection->fetchOne('SELECT col_description(a.attrelid, a.attnum) FROM pg_catalog.pg_attribute a WHERE a.attrelid=to_regclass(?) AND a.attname=? AND NOT a.attisdropped', [$quote($table), 'items_id']);
                $this->original[$table] = ['check' => $check['definition'], 'comment' => $nativeComment];
                if ($tables !== null) {
                    $this->original[$table]['projection'] = $connection->fetchOne('SELECT pg_get_expr(d.adbin,d.adrelid) FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=to_regclass(?) AND a.attname=? AND NOT a.attisdropped', [$quote($table), 'items_id']);
                    if (!is_string($this->original[$table]['projection']) || $this->original[$table]['projection'] === '') {
                        throw new LogicException('Native stored projection required before selected reconstruction.');
                    }
                }
            }
            if ($tables !== null) {
                $this->restorationFacts[$table] = $this->facts($table);
            }
            if ($platform instanceof MySQLPlatform) {
                $generated = ['items_id'];
                foreach ($this->restorationFacts[$table]['native']['columns'] ?? [] as $name => $column) {
                    if (($column['GENERATION_EXPRESSION'] ?? '') !== '' && $name !== 'items_id') {
                        $generated[] = $name;
                    }
                }
                $create = $connection->fetchAssociative('SHOW CREATE TABLE ' . $quote($table));
                if (!is_array($create) || !is_string($create['Create Table'] ?? null)) {
                    throw new LogicException('Capture actual executable native subject declarations before alteration.');
                }
                $this->mysqlDeclarations[$table] = MySQLNativeSubjectDeclaration::capture(
                    $create['Create Table'], $table, $generated, $definition['constraint'],
                    !in_array('NO_BACKSLASH_ESCAPES', explode(',', $this->mysqlDeclarationMode), true),
                    in_array('ANSI_QUOTES', explode(',', $this->mysqlDeclarationMode), true)
                );
            }
            if ($tables !== null) {
                if ($captureTableDeclarations) {
                    $this->restorationTables[$table] = $this->captureRestorationTable($table);
                }
            }
        }
        $this->assertSelectedReconstruction();
    }

    /** Captured native declaration for cleanup only; historical test schemas stay separate. */
    public function restorationTable(string $table): Table
    {
        if (!$this->detached || !isset($this->restorationTables[$table])) {
            throw new LogicException('A selected owned reconstruction is required before requesting its cleanup declaration.');
        }
        $this->assertNativeDeclarationMode();
        return clone $this->restorationTables[$table];
    }

    private function captureRestorationTable(string $table): Table
    {
        $manager = $this->connection->createSchemaManager();
        $captured = $manager->introspectTable($table);
        // Table hydration synthesizes FK-support indexes. listTableIndexes reads
        // actual indexes without constructing a Table or inventing those owners.
        $nativeNames = [];
        foreach ($manager->listTableIndexes($table) as $index) {
            $nativeNames[$index->getName()] = true;
        }
        foreach ($captured->getIndexes() as $index) {
            if (!isset($nativeNames[$index->getName()])) {
                $captured->dropIndex($index->getName());
            }
        }
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            foreach ($this->restorationFacts[$table]['native']['columns'] as $name => $native) {
                // DBAL maps TIMESTAMP to datetime and loses its native declaration.
                // Admit only the actually captured simple nullable timestamp form;
                // do not parse or silently approximate other native DEFAULT/EXTRA.
                if (!preg_match('/^timestamp(?:\([0-6]\))?$/iD', $native['COLUMN_TYPE'])) {
                    continue;
                }
                $column = $captured->getColumn($name);
                // The schema manager owns provider default parsing, including
                // MariaDB's native string NULL representation of a NULL default.
                if ($native['IS_NULLABLE'] !== 'YES' || $column->getDefault() !== null || $native['EXTRA'] !== '') {
                    throw new LogicException('Unsupported native timestamp shape before selected fixture reconstruction: ' . $table . '.' . $name);
                }
                $declaration = $native['COLUMN_TYPE'] . ' NULL DEFAULT NULL';
                if ($column->getComment() !== null && $column->getComment() !== '') {
                    $declaration .= ' ' . $platform->getInlineColumnCommentSQL($column->getComment());
                }
                $column->setColumnDefinition($declaration);
            }
        }
        if ($platform instanceof PostgreSQLPlatform) {
            foreach ($this->restorationFacts[$table]['native']['columns'] as $name => $native) {
                $column = $captured->getColumn($name);
                if ($native['column_default'] === null || $column->getDefault() !== null || !$column->getNotnull()) {
                    continue;
                }
                // PostgreSQLSchemaManager collapses an explicit NULL:: default
                // into PHP null. Keep the observed finite native expression in
                // DBAL's default seam, preserving the other column properties.
                if ($native['column_default'] !== 'NULL::character varying'
                    || $native['data_type'] !== 'character varying' || $native['udt_schema'] !== 'pg_catalog' || $native['udt_name'] !== 'varchar'
                    || $native['is_identity'] !== 'NO' || $native['is_generated'] !== 'NEVER'
                    || $native['is_nullable'] !== 'NO'
                    || $column->getAutoincrement() || $column->getColumnDefinition() !== null) {
                    throw new LogicException('Unsupported collapsed native default before selected reconstruction: ' . $table . '.' . $name);
                }
                $column->setDefault(new class ($native['column_default']) implements DefaultExpression {
                    public function __construct(private readonly string $expression)
                    {
                    }

                    public function toSQL(AbstractPlatform $platform): string
                    {
                        return $this->expression;
                    }
                });
            }
        }
        $generated = ['items_id' => $this->original[$table]['projection']];
        foreach ($this->restorationFacts[$table]['native']['columns'] as $name => $native) {
            if ($platform instanceof AbstractMySQLPlatform) {
                if ($name === 'items_id' || ($native['GENERATION_EXPRESSION'] ?? '') !== '') {
                    if ($native['EXTRA'] !== 'STORED GENERATED') {
                        throw new LogicException('Unsupported generated storage before selected reconstruction: ' . $table . '.' . $name);
                    }
                    if ($name !== 'items_id') {
                        $generated[$name] = $native['GENERATION_EXPRESSION'];
                    }
                }
            } elseif ($name !== 'items_id' && $native['is_generated'] === 'ALWAYS') {
                if (!is_string($native['generation_expression']) || $native['generation_expression'] === '') {
                    throw new LogicException('Missing native generated expression before selected reconstruction: ' . $table . '.' . $name);
                }
                $generated[$name] = $native['generation_expression'];
            }
        }
        if (!$platform instanceof AbstractMySQLPlatform) {
            $storage = $this->connection->fetchAllAssociative("SELECT attname, attgenerated FROM pg_catalog.pg_attribute WHERE attrelid=to_regclass(?) AND NOT attisdropped AND attgenerated<>''", [$this->connection->quoteIdentifier($table)]);
            foreach ($storage as $native) {
                if ($native['attgenerated'] !== 's' || !isset($generated[$native['attname']])) {
                    throw new LogicException('Unsupported native generated storage before selected reconstruction: ' . $table);
                }
            }
            if (count($storage) !== count($generated)) {
                throw new LogicException('Incomplete native generation capture before selected reconstruction: ' . $table);
            }
        }
        foreach ($generated as $name => $expression) {
            $projection = $captured->getColumn($name);
            // PostgreSQL hydrates generated expressions as DEFAULTs; MySQL
            // loses generation entirely. Retain each actual stored owner once.
            $projection->setDefault(null);
            $projection->setAutoincrement(false);
            if ($platform instanceof MySQLPlatform) {
                $projection->setColumnDefinition($this->mysqlDeclarations[$table]['columns'][$name]);
                continue;
            }
            $declaration = $projection->getType()->getSQLDeclaration($projection->toArray(), $platform)
                . ' GENERATED ALWAYS AS (' . $expression . ') STORED'
                . ($projection->getNotnull() ? ' NOT NULL' : '');
            if ($platform->supportsInlineColumnComments() && $projection->getComment() !== null && $projection->getComment() !== '') {
                $declaration .= ' ' . $platform->getInlineColumnCommentSQL($projection->getComment());
            }
            $projection->setColumnDefinition($declaration);
        }
        return $captured;
    }

    /** Current valid rows survive old collation behavior; deliberately bad rows are owned later. */
    public function detach(): void
    {
        $this->beginOwnedAlteration();
        foreach ($this->definitions as $table => $definition) {
            $platform = $this->connection->getDatabasePlatform();
            if ($platform instanceof AbstractMySQLPlatform) {
                // Frozen branch scope; this fixture alone models the older
                // collation-sensitive generation, never current ORM metadata.
                $cases = [];
                foreach ($definition['branches'] as $kind => $branch) {
                    $cases[] = 'WHEN ' . $platform->quoteIdentifier('itemtype') . ' = ' . $platform->quoteStringLiteral($kind)
                        . ' THEN ' . $platform->quoteIdentifier($branch['column']);
                }
                $projection = 'CASE ' . implode(' ', $cases) . ' ELSE ' . ($definition['empty_value'] ?? 'NULL') . ' END';
                // TRUE intentionally admits owned corrupt rows on both engines.
                // This is a diagnostic fixture, not a claimed supported old schema.
                $this->replace($table, $projection, 'CHECK (TRUE)', $this->original[$table]['comment']);
            } else {
                $this->replace($table, null, 'CHECK (TRUE)', $this->original[$table]['comment']);
            }
        }
    }

    /** Selected reconstruction owns the captured receipt before any historical DDL. */
    public function beginOwnedAlteration(): void
    {
        $this->assertSelectedReconstruction();
        $this->detached = true; // A failed receipt deletion remains owned cleanup.
        $this->connection->delete(LegacyToOrm::LEDGER, ['version' => ExactDiscriminators20261010::VERSION]);
    }

    private function assertSelectedReconstruction(): void
    {
        foreach ($this->restorationFacts as $table => $facts) {
            if ($this->facts($table) !== $facts) {
                throw new LogicException('Selected native schema changed before reconstruction ownership: ' . $table);
            }
            if ($facts['native']['incoming'] !== []) {
                throw new LogicException('Selected table reconstruction cannot detach incoming projection owners: ' . $table);
            }
            if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $dependent = $this->connection->fetchOne('SELECT COUNT(*) FROM pg_catalog.pg_constraint c JOIN pg_catalog.pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=ANY(c.conkey) WHERE c.conrelid=to_regclass(?) AND a.attname=?', [$this->connection->quoteIdentifier($table), 'items_id']);
                if ((int)$dependent !== 0) {
                    throw new LogicException('Selected projection has an additional native constraint owner: ' . $table);
                }
            }
        }
    }

    /** Restore original native definitions first; receipt only follows verified restoration. */
    public function restore(): void
    {
        if (!$this->detached) {
            return;
        }
        if ($this->restorationFacts !== []) {
            // A tested History replay may have completed the receipt again.
            // Own its removal before any fallible native restoration.
            $this->connection->delete(LegacyToOrm::LEDGER, ['version' => ExactDiscriminators20261010::VERSION]);
        }
        $errors = [];
        foreach ($this->original as $table => $definition) {
            try {
                $this->replace($table, $definition['projection'] ?? null, $definition['check'], $definition['comment'], true);
            } catch (Throwable $error) {
                $errors[] = $error;
            }
        }
        if ($errors) {
            // Do not restore a completion receipt over partially restored DDL.
            throw new RuntimeException('Historical subject fixture native restoration failed; completion receipt was not restored.', previous: $errors[0]);
        }
        $platform = $this->connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $catalog = $platform instanceof AbstractMySQLPlatform ? BooleanDomainSchema::catalog($this->connection) : null;
        foreach ($this->original as $table => $definition) {
            $name = $this->definitions[$table]['constraint'];
            if ($platform instanceof AbstractMySQLPlatform) {
                $projection = $this->connection->fetchOne('SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'items_id']);
                $actual = ['projection' => $projection, 'check' => 'CHECK (' . ($catalog['checks'][$table][$name]['clause'] ?? '') . ')',
                    'comment' => (string)$this->connection->createSchemaManager()->introspectTable($table)->getColumn('items_id')->getComment()];
                if (($catalog['checks'][$table][$name]['enforced'] ?? null) !== 'YES') {
                    throw new RuntimeException('Historical fixture CHECK restoration did not enforce its captured definition.');
                }
            } else {
                $actual = ['check' => $this->connection->fetchOne('SELECT pg_get_constraintdef(oid) FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND conname=?', [$quote($table), $name]),
                    'comment' => $this->connection->fetchOne('SELECT col_description(a.attrelid, a.attnum) FROM pg_catalog.pg_attribute a WHERE a.attrelid=to_regclass(?) AND a.attname=? AND NOT a.attisdropped', [$quote($table), 'items_id'])];
                if (array_key_exists('projection', $definition)) {
                    $actual['projection'] = $this->connection->fetchOne('SELECT pg_get_expr(d.adbin,d.adrelid) FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=to_regclass(?) AND a.attname=? AND NOT a.attisdropped', [$quote($table), 'items_id']);
                }
            }
            if ($actual !== $definition) {
                throw new RuntimeException('Historical fixture native definitions did not restore exactly; completion receipt was not restored.');
            }
            if (isset($this->restorationFacts[$table]) && $this->facts($table) !== $this->restorationFacts[$table]) {
                throw new RuntimeException('Selected fixture changed native columns, indexes, references or other CHECKs; completion receipt was not restored: ' . $table);
            }
        }
        $this->connection->delete(LegacyToOrm::LEDGER, ['version' => ExactDiscriminators20261010::VERSION]);
        if ($this->originalLedger !== null) {
            $expected = array_values(array_filter($this->originalLedger, static fn (array $row): bool => $row['version'] !== ExactDiscriminators20261010::VERSION));
            $actual = $this->connection->fetchAllAssociative('SELECT version, state FROM ' . $quote(LegacyToOrm::LEDGER) . ' ORDER BY version');
            if ($actual !== $expected) {
                throw new RuntimeException('Historical fixture changed another raw history receipt; Exact completion was not restored.');
            }
        }
        $this->connection->insert(LegacyToOrm::LEDGER, $this->receipt);
        $this->detached = false;
    }

    /** Preserve the actual fixture failure if independent native restoration also fails. */
    public function restorePreservingFailure(?Throwable $primary): void
    {
        try {
            $this->restore();
        } catch (Throwable $cleanup) {
            if ($primary !== null) {
                throw new RuntimeException('Historical fixture failed and captured native cleanup also failed: '
                    . $cleanup::class . ': ' . $cleanup->getMessage(), previous: $primary);
            }
            throw $cleanup;
        }
        if ($primary !== null) {
            throw $primary;
        }
    }

    /** Actual schema facts; physical column positions and allocator advances are not ownership. */
    private function facts(string $table): array
    {
        $method = new ReflectionMethod(ExactDiscriminators20261010::class, 'preservation');
        $facts = $method->invoke(null, $this->connection, $this->connection->createSchemaManager()->introspectTable($table));
        unset($facts['native']['table']['AUTO_INCREMENT']);
        if (isset($facts['native']['columns'])) {
            $columns = [];
            foreach ($facts['native']['columns'] as $column) {
                $columns[$column['COLUMN_NAME']] = $column;
            }
            ksort($columns);
            $facts['native']['columns'] = $columns;
        } else {
            $columns = $this->connection->fetchAllAssociative('SELECT column_name, data_type, udt_schema, udt_name, character_maximum_length, numeric_precision, numeric_scale, datetime_precision, is_nullable, column_default, is_identity, identity_generation, is_generated, generation_expression, collation_schema, collation_name FROM information_schema.columns WHERE (table_schema, table_name) = (SELECT n.nspname, c.relname FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE c.oid=to_regclass(?)) ORDER BY column_name', [$this->connection->quoteIdentifier($table)]);
            $facts['native']['columns'] = array_column($columns, null, 'column_name');
            $facts['native']['column_comments'] = $this->connection->fetchAllAssociative('SELECT a.attname, col_description(a.attrelid,a.attnum) AS comment FROM pg_catalog.pg_attribute a WHERE a.attrelid=to_regclass(?) AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attname', [$this->connection->quoteIdentifier($table)]);
            $facts['native']['constraints'] = $this->connection->fetchAllAssociative('SELECT conname, contype, convalidated, pg_get_constraintdef(oid) AS definition FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND conname<>? ORDER BY conname, contype', [$this->connection->quoteIdentifier($table), $this->definitions[$table]['constraint']]);
            $facts['native']['indexes'] = $this->connection->fetchAllAssociative('SELECT c.relname, i.indisvalid, i.indisready, pg_get_indexdef(i.indexrelid) AS definition FROM pg_catalog.pg_index i JOIN pg_catalog.pg_class c ON c.oid=i.indexrelid WHERE i.indrelid=to_regclass(?) ORDER BY c.relname', [$this->connection->quoteIdentifier($table)]);
        }
        return $facts;
    }

    private function replace(string $table, ?string $projection, string $check, ?string $comment, bool $restoreNative = false): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $name = $this->definitions[$table]['constraint'];
        $sql = 'ALTER TABLE ' . $quote($table) . ' DROP ' . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $quote($name);
        $indexes = [];
        if ($restoreNative && $platform instanceof MySQLPlatform) {
            $this->assertNativeDeclarationMode();
            $sql .= ', MODIFY COLUMN ' . $quote('items_id') . ' ' . $this->mysqlDeclarations[$table]['columns']['items_id'];
        } elseif ($projection !== null && $platform instanceof AbstractMySQLPlatform) {
            $sql .= ', MODIFY COLUMN ' . $quote('items_id') . ' BIGINT GENERATED ALWAYS AS (' . $projection . ') STORED';
            if ($comment !== '') {
                $sql .= ' ' . $platform->getInlineColumnCommentSQL($comment);
            }
        }
        if ($projection !== null && !$platform instanceof AbstractMySQLPlatform) {
            $current = $this->connection->fetchOne('SELECT pg_get_expr(d.adbin,d.adrelid) FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=to_regclass(?) AND a.attname=? AND NOT a.attisdropped', [$quote($table), 'items_id']);
            if ($current !== $projection) {
                // The selected reconstruction admitted no incoming/constraint owner.
                // Check again before replacing a native generated column.
                $facts = $this->facts($table);
                if ($facts['indexes'] !== $this->restorationFacts[$table]['indexes']
                    || $facts['foreign_keys'] !== $this->restorationFacts[$table]['foreign_keys']
                    || $facts['native']['constraints'] !== $this->restorationFacts[$table]['native']['constraints']
                    || $facts['native']['indexes'] !== $this->restorationFacts[$table]['native']['indexes']) {
                    throw new RuntimeException('Changed native index or constraint owner prevents selected projection restoration: ' . $table);
                }
                if ($facts['native']['incoming'] !== []) {
                    throw new RuntimeException('New incoming projection owner prevents selected fixture restoration: ' . $table);
                }
                $dependent = $this->connection->fetchOne('SELECT COUNT(*) FROM pg_catalog.pg_constraint c JOIN pg_catalog.pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=ANY(c.conkey) WHERE c.conrelid=to_regclass(?) AND a.attname=?', [$quote($table), 'items_id']);
                if ((int)$dependent !== 0) {
                    throw new RuntimeException('New projection constraint owner prevents selected fixture restoration: ' . $table);
                }
                $indexes = $this->connection->fetchFirstColumn("SELECT pg_get_indexdef(i.indexrelid) FROM pg_catalog.pg_index i WHERE i.indrelid=to_regclass(?) AND EXISTS (SELECT 1 FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_attribute a ON a.attrelid=d.refobjid AND a.attnum=d.refobjsubid WHERE d.classid='pg_class'::regclass AND d.objid=i.indexrelid AND d.refobjid=i.indrelid AND a.attname=?) ORDER BY i.indexrelid", [$quote($table), 'items_id']);
                $sql .= ', DROP COLUMN ' . $quote('items_id') . ', ADD COLUMN ' . $quote('items_id')
                    . ' BIGINT GENERATED ALWAYS AS (' . $projection . ') STORED';
            }
        }
        $sql .= $restoreNative && $platform instanceof MySQLPlatform
            ? ', ADD ' . $this->mysqlDeclarations[$table]['check']
            : ', ADD CONSTRAINT ' . $quote($name) . ' ' . $check . ($platform instanceof MySQLPlatform ? ' ENFORCED' : '');
        $this->connection->executeStatement($sql);
        foreach ($indexes as $index) {
            $this->connection->executeStatement($index);
        }
        if (!$platform instanceof AbstractMySQLPlatform) {
            $this->connection->executeStatement('COMMENT ON COLUMN ' . $quote($table) . '.' . $quote('items_id') . ' IS ' . ($comment === null ? 'NULL' : $platform->quoteStringLiteral($comment)));
        }
    }

    private function assertNativeDeclarationMode(): void
    {
        if ($this->mysqlDeclarationMode !== null
            && (string)$this->connection->fetchOne('SELECT @@SESSION.sql_mode') !== $this->mysqlDeclarationMode) {
            throw new LogicException('Native subject declaration SQL mode changed; completion receipt remains withheld.');
        }
    }
}
