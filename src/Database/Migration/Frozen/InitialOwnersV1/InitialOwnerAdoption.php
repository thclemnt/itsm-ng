<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\Frozen\InitialOwnersV1;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use RuntimeException;
use itsmng\Database\Migration\V220\IncomingProjectionReferences;
use itsmng\Database\MySQLGeneratedColumnInspection;
use itsmng\Database\NativeCheckCatalog;
use itsmng\Database\NativeSubjectSchema;

/** Frozen initial multi-owner adoption; all writers remain stopped and drained through retry. */
abstract class InitialOwnerAdoption
{
    public const TABLE = '';
    public const OWNERS = [];
    public const CHECK = '';

    /** Frozen declarations, independently of current entity metadata or UI affinities. */
    public static function policy(AbstractPlatform $platform): array
    {
        $quote = $platform->quoteIdentifier(...);
        $kind = $quote('itemtype');
        if ($platform instanceof AbstractMySQLPlatform) {
            $kind = 'CAST(' . $kind . ' AS BINARY)';
        }
        $cases = $selected = $unselected = [];
        $types = ['opaque_parent_id' => 'bigint'];
        foreach (static::OWNERS as $literal => [$column]) {
            $test = $kind . ' IN (' . $platform->quoteStringLiteral($literal) . ')';
            $owner = $quote($column);
            $cases[] = 'WHEN ' . $test . ' THEN COALESCE(' . $owner . ', 0)';
            $branch = [$test, '(' . $owner . ' IS NULL OR ' . $owner . ' > 0)', $quote('opaque_parent_id') . ' IS NULL'];
            foreach (static::OWNERS as [$other]) {
                if ($other !== $column) {
                    $branch[] = $quote($other) . ' IS NULL';
                }
            }
            $selected[] = '(' . implode(' AND ', $branch) . ')';
            $unselected[] = $kind . ' NOT IN (' . $platform->quoteStringLiteral($literal) . ')';
            $types[$column] = 'bigint';
        }
        foreach (static::OWNERS as [$column]) {
            $unselected[] = $quote($column) . ' IS NULL';
        }
        $unselected[] = $quote('opaque_parent_id') . ' IS NOT NULL';
        $selected[] = '(' . implode(' AND ', $unselected) . ')';
        return [
            'projection' => 'CASE ' . implode(' ', $cases) . ' ELSE ' . $quote('opaque_parent_id') . ' END',
            'constraint' => static::CHECK,
            'check' => implode(' OR ', $selected),
            'discriminators' => ['itemtype'],
            'integer_types' => $types,
            'string_selections' => ['itemtype' => array_keys(static::OWNERS)],
            'open_string_fallback' => true,
        ];
    }

    /** Fixed simultaneous copy; caller must prove original provenance and empty/complete state. */
    public static function copyStatement(AbstractPlatform $platform): string
    {
        $quote = $platform->quoteIdentifier(...);
        $kind = $quote('itemtype');
        if ($platform instanceof AbstractMySQLPlatform) {
            $kind = 'CAST(' . $kind . ' AS BINARY)';
        }
        $set = $selected = [];
        foreach (static::OWNERS as $literal => [$column]) {
            $test = $kind . ' IN (' . $platform->quoteStringLiteral($literal) . ')';
            $set[] = $quote($column) . ' = CASE WHEN ' . $test . ' AND ' . $quote('items_id') . ' > 0 THEN ' . $quote('items_id') . ' ELSE NULL END';
            $selected[] = $test;
        }
        $set[] = $quote('opaque_parent_id') . ' = CASE WHEN ' . implode(' OR ', $selected) . ' THEN NULL ELSE ' . $quote('items_id') . ' END';
        return 'UPDATE ' . $quote(static::TABLE) . ' SET ' . implode(', ', $set);
    }

    /** Read-only fresh admission: no ledger bootstrap, DDL, locks or source mutation. */
    public function inspectSource(Connection $connection): array
    {
        $platform = $connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof PostgreSQLPlatform) {
            throw new RuntimeException('Initial adoption supports only the reviewed MySQL/Maria and PostgreSQL platforms.');
        }
        $table = $connection->createSchemaManager()->introspectTable(static::TABLE);
        if (!$table->hasColumn('items_id') || !$table->hasColumn('itemtype')) {
            throw new RuntimeException('Missing original initial-adoption identity: ' . static::TABLE);
        }
        $identity = $table->getColumn('items_id');
        $kind = $table->getColumn('itemtype');
        if (
            Type::lookupName($identity->getType()) !== 'bigint' || !$identity->getNotnull()
            || $identity->getUnsigned() || $identity->getAutoincrement() || (string)$identity->getDefault() !== '0'
            || Type::lookupName($kind->getType()) !== 'string' || !$kind->getNotnull() || $kind->getLength() !== 100
        ) {
            throw new RuntimeException('Initial adoption requires its original signed BIGINT/default0 and nonnull VARCHAR100 source: ' . static::TABLE);
        }
        foreach ([...array_column(static::OWNERS, 0), 'opaque_parent_id'] as $column) {
            if ($table->hasColumn($column)) {
                throw new RuntimeException('Unjournaled initial owning column: ' . static::TABLE . '.' . $column);
            }
        }
        $mysql = $platform instanceof AbstractMySQLPlatform;
        foreach (static::OWNERS as [$column, , $mysqlIndex, $postgresIndex]) {
            $index = $mysql ? $mysqlIndex : $postgresIndex;
            $foreign = 'fk_' . substr(static::TABLE, 5) . '_' . $column;
            if ($table->hasIndex($index) || $table->hasForeignKey($foreign)) {
                throw new RuntimeException('Unjournaled planned owning index/FK name: ' . static::TABLE . '.' . $column);
            }
        }
        $schema = (string)$connection->fetchOne($platform instanceof AbstractMySQLPlatform ? 'SELECT DATABASE()' : 'SELECT current_schema()');
        if ((new IncomingProjectionReferences($connection))->has($schema, static::TABLE)) {
            throw new RuntimeException('Incoming initial logical identity FK requires a separate migration: ' . static::TABLE);
        }
        foreach ($table->getForeignKeys() as $foreign) {
            if (in_array('items_id', $foreign->getLocalColumns(), true)) {
                throw new RuntimeException('Outgoing initial logical identity FK requires a separate migration: ' . static::TABLE);
            }
        }
        $catalog = NativeCheckCatalog::snapshot($connection, static::TABLE);
        if (isset($catalog['checks'][static::TABLE][static::CHECK])) {
            throw new RuntimeException('Unjournaled planned initial CHECK name: ' . static::TABLE . '.' . static::CHECK);
        }
        foreach ($catalog['checks'][static::TABLE] ?? [] as $check) {
            $fields = $check['checked_columns'] ?? [];
            $fields = is_string($fields) ? json_decode($fields, true, flags: JSON_THROW_ON_ERROR) : $fields;
            if ((is_array($fields) && in_array('items_id', $fields, true))
                || ($platform instanceof AbstractMySQLPlatform && preg_match('/\bitems_id\b/i', $check['clause'] ?? ''))) {
                throw new RuntimeException('Custom identity CHECK requires separate reviewed adoption: ' . static::TABLE);
            }
        }
        $native = $platform instanceof AbstractMySQLPlatform
            ? $this->inspectMysql($connection, $schema, $table)
            : $this->inspectPostgres($connection, $table);
        foreach (static::OWNERS as $literal => [, $target]) {
            $targetTable = $connection->createSchemaManager()->introspectTable($target);
            $targetId = $targetTable->getColumn('id');
            if (Type::lookupName($targetId->getType()) !== 'bigint' || !$targetId->getNotnull() || $targetId->getUnsigned()
                || $targetTable->getPrimaryKey()?->getUnquotedColumns() !== ['id']) {
                throw new RuntimeException('Initial owning target must have a signed nonnull BIGINT identity: ' . $target);
            }
            if ($mysql && strcasecmp((string)$connection->fetchOne(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',
                [$schema, $target]
            ), 'InnoDB') !== 0) {
                throw new RuntimeException('Initial owning target must use InnoDB: ' . $target);
            }
            $rows = $connection->fetchAllAssociative(
                'SELECT n.id, n.itemtype, n.items_id FROM ' . $platform->quoteIdentifier(static::TABLE) . ' n LEFT JOIN '
                . $platform->quoteIdentifier($target) . ' p ON p.id=n.items_id WHERE n.itemtype IS NULL OR n.items_id IS NULL OR ('
                . ($platform instanceof AbstractMySQLPlatform ? 'CAST(n.itemtype AS BINARY)' : 'n.itemtype')
                . ' IN (' . $platform->quoteStringLiteral($literal) . ') AND (n.items_id<0 OR (n.items_id>0 AND p.id IS NULL))) ORDER BY n.id LIMIT 5'
            );
            if ($rows) {
                throw new RuntimeException('Invalid initial ' . $literal . ' identities: ' . json_encode($rows, JSON_THROW_ON_ERROR));
            }
        }
        return ['table' => static::TABLE, 'native' => $native, 'affected_indexes' => $this->affectedIndexes($table)];
    }

    /** Only native visibility of the authenticated principal proves trigger absence. */
    private function assertMysqlTriggerVisibility(Connection $connection, string $schema): void
    {
        $account = $connection->fetchOne('SELECT CURRENT_USER()');
        if (!is_string($account) || !preg_match('/\A([a-zA-Z0-9_.$-]+)@([a-zA-Z0-9_.%:-]+)\z/D', $account, $parts)) {
            throw new RuntimeException('Initial adoption supports only directly granted, simple CURRENT_USER account spellings; no role or quoted-account visibility was inferred.');
        }
        $grantee = "'" . $parts[1] . "'@'" . $parts[2] . "'";
        $visible = $connection->fetchOne(
            "SELECT COUNT(*) FROM (SELECT GRANTEE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=? AND PRIVILEGE_TYPE='TRIGGER' "
            . "UNION ALL SELECT GRANTEE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=? AND TABLE_SCHEMA=? AND PRIVILEGE_TYPE='TRIGGER' "
            . "UNION ALL SELECT GRANTEE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=? AND TABLE_SCHEMA=? AND TABLE_NAME=? AND PRIVILEGE_TYPE='TRIGGER') direct_grants",
            [$grantee, $grantee, $schema, $grantee, $schema, static::TABLE]
        );
        if ((int)$visible < 1) {
            throw new RuntimeException('Cannot prove source trigger visibility for ' . static::TABLE . ': require a direct TRIGGER grant on this table or its database. Role-only grants are not supported by this adoption inspection.');
        }
        if ((int)$connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? AND EVENT_OBJECT_TABLE=?',
            [$schema, static::TABLE]
        ) !== 0) {
            throw new RuntimeException('User source triggers require separate reviewed adoption: ' . static::TABLE);
        }
    }

    private function inspectMysql(Connection $connection, string $schema, Table $table): array
    {
        if ($connection->isTransactionActive() || (int)$connection->fetchOne('SELECT @@SESSION.foreign_key_checks') !== 1) {
            throw new RuntimeException('Initial MySQL adoption requires enabled foreign keys and no application transaction.');
        }
        if (strcasecmp((string)$connection->fetchOne(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',
            [$schema, static::TABLE]
        ), 'InnoDB') !== 0) {
            throw new RuntimeException('Initial source must use InnoDB: ' . static::TABLE);
        }
        $this->assertMysqlTriggerVisibility($connection, $schema);
        if ((int)$connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND PARTITION_NAME IS NOT NULL',
            [$schema, static::TABLE]
        ) !== 0 || MySQLGeneratedColumnInspection::listGeneratedColumns($connection, $schema, static::TABLE) !== []) {
            throw new RuntimeException('Partitioned or already generated initial sources require separate reviewed adoption: ' . static::TABLE);
        }
        $columns = $connection->fetchAllAssociative(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT, COLLATION_NAME FROM '
            . 'information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',
            [$schema, static::TABLE]
        );
        foreach ($columns as $column) {
            if (str_contains(strtolower((string)$column['EXTRA']), 'on update')) {
                throw new RuntimeException('Automatic-update source payload requires separate reviewed adoption: ' . static::TABLE . '.' . $column['COLUMN_NAME']);
            }
        }
        $indexes = $connection->fetchAllAssociative('SHOW INDEX FROM ' . $connection->getDatabasePlatform()->quoteIdentifier(static::TABLE));
        $affected = array_column($this->affectedIndexes($table), 'name');
        foreach ($indexes as &$index) {
            unset($index['Cardinality']);
            if (in_array($index['Key_name'], $affected, true) && (
                $index['Index_type'] !== 'BTREE' || $index['Sub_part'] !== null || ($index['Expression'] ?? null) !== null
                || !in_array($index['Collation'], ['A', null], true) || ($index['Visible'] ?? 'YES') !== 'YES'
                || ($index['Ignored'] ?? 'NO') !== 'NO'
            )) {
                throw new RuntimeException('Unsupported affected native index: ' . static::TABLE . '.' . $index['Key_name']);
            }
        }
        unset($index);
        return ['columns' => $columns, 'indexes' => $indexes];
    }

    private function inspectPostgres(Connection $connection, Table $table): array
    {
        $relation = $connection->fetchAssociative(
            'SELECT c.oid::text AS oid, c.relkind, c.relispartition, c.relrowsecurity, c.relforcerowsecurity FROM pg_catalog.pg_class c WHERE c.oid=to_regclass(?)',
            [static::TABLE]
        );
        if (!is_array($relation) || $relation['relkind'] !== 'r' || !in_array($relation['relispartition'], [false, 0, '0', 'f'], true)
            || !in_array($relation['relrowsecurity'], [false, 0, '0', 'f'], true)
            || !in_array($relation['relforcerowsecurity'], [false, 0, '0', 'f'], true)
            || (int)$connection->fetchOne('SELECT COUNT(*) FROM pg_catalog.pg_inherits WHERE inhrelid=to_regclass(?) OR inhparent=to_regclass(?)', [static::TABLE, static::TABLE]) !== 0) {
            throw new RuntimeException('Inherited/partitioned or row-security-filtered initial sources require separate reviewed adoption: ' . static::TABLE);
        }
        if ((int)$connection->fetchOne('SELECT COUNT(*) FROM pg_catalog.pg_trigger WHERE tgrelid=to_regclass(?) AND NOT tgisinternal', [static::TABLE]) !== 0) {
            throw new RuntimeException('User source triggers require separate reviewed adoption: ' . static::TABLE);
        }
        $kind = $connection->fetchAssociative(
            'SELECT a.attgenerated, c.collisdeterministic FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_collation c ON '
            . 'c.oid=a.attcollation WHERE a.attrelid=to_regclass(?) AND a.attname=? AND NOT a.attisdropped',
            [static::TABLE, 'itemtype']
        );
        if (!is_array($kind) || $kind['attgenerated'] !== '' || !in_array($kind['collisdeterministic'], [true, 1, '1', 't'], true)) {
            throw new RuntimeException('Initial discriminator requires its existing deterministic stored VARCHAR collation: ' . static::TABLE);
        }
        if ($connection->fetchOne('SELECT attgenerated FROM pg_catalog.pg_attribute WHERE attrelid=to_regclass(?) AND attname=? AND NOT attisdropped', [static::TABLE, 'items_id']) !== '') {
            throw new RuntimeException('Initial identity must still be stored: ' . static::TABLE);
        }
        $dependencies = $connection->fetchOne(
            "SELECT COUNT(*) FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_attribute a ON a.attrelid=d.refobjid AND a.attnum=d.refobjsubid "
            . "WHERE a.attrelid=to_regclass(?) AND a.attname='items_id' AND (d.classid='pg_catalog.pg_statistic_ext'::regclass OR d.classid='pg_catalog.pg_rewrite'::regclass OR "
            . "(d.classid='pg_catalog.pg_attrdef'::regclass AND d.objid NOT IN (SELECT oid FROM pg_catalog.pg_attrdef WHERE adrelid=a.attrelid AND adnum=a.attnum)) OR "
            . "(d.classid='pg_catalog.pg_class'::regclass AND EXISTS (SELECT 1 FROM pg_catalog.pg_index i WHERE i.indexrelid=d.objid AND (i.indexprs IS NOT NULL OR i.indpred IS NOT NULL))))",
            [static::TABLE]
        );
        if ((int)$dependencies !== 0) {
            throw new RuntimeException('Dependent identity view/default/generated expression/statistics requires separate reviewed adoption: ' . static::TABLE);
        }
        $indexes = $connection->fetchAllAssociative(
            "SELECT ic.relname AS name, am.amname AS method, i.indisprimary, i.indisunique, i.indisclustered, i.indisreplident, "
            . "i.indisvalid, i.indisready, i.indislive, "
            . "i.indnkeyatts, i.indnatts, i.indkey::text AS keys, i.indoption::text AS options, i.indexprs IS NULL AS plain, "
            . "i.indpred IS NULL AS unfiltered, ic.reltablespace::text AS tablespace, ic.reloptions, "
            . "pg_get_indexdef(i.indexrelid) AS definition, obj_description(i.indexrelid,'pg_class') AS comment, "
            . "EXISTS (SELECT 1 FROM unnest(i.indclass) x(opclass) JOIN pg_catalog.pg_opclass op ON op.oid=x.opclass WHERE NOT op.opcdefault) AS custom_opclass, "
            . "EXISTS (SELECT 1 FROM unnest(i.indcollation::oid[]) WITH ORDINALITY x(collation, ord) JOIN unnest(i.indkey::smallint[]) WITH ORDINALITY y(attnum, ord) USING (ord) JOIN pg_catalog.pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=y.attnum WHERE x.collation<>a.attcollation) AS custom_collation, "
            . "EXISTS (SELECT 1 FROM pg_catalog.pg_constraint c WHERE c.conindid=i.indexrelid) AS constrained "
            . "FROM pg_catalog.pg_index i JOIN pg_catalog.pg_class ic ON ic.oid=i.indexrelid JOIN pg_catalog.pg_am am ON am.oid=ic.relam "
            . "WHERE i.indrelid=to_regclass(?) AND EXISTS (SELECT 1 FROM pg_catalog.pg_attribute a WHERE a.attrelid=i.indrelid AND a.attname='items_id' AND a.attnum=ANY(i.indkey)) ORDER BY ic.relname",
            [static::TABLE]
        );
        foreach ($indexes as $index) {
            if ($index['method'] !== 'btree' || !in_array($index['plain'], [true, 1, '1', 't'], true)
                || !in_array($index['indisvalid'], [true, 1, '1', 't'], true)
                || !in_array($index['indisready'], [true, 1, '1', 't'], true)
                || !in_array($index['indislive'], [true, 1, '1', 't'], true)
                || !in_array($index['unfiltered'], [true, 1, '1', 't'], true) || (int)$index['indnkeyatts'] !== (int)$index['indnatts']
                || !preg_match('/\A0(?: 0)*\z/D', $index['options']) || $index['tablespace'] !== '0' || $index['reloptions'] !== null
                || !in_array($index['constrained'], [false, 0, '0', 'f'], true) || !in_array($index['indisclustered'], [false, 0, '0', 'f'], true)
                || !in_array($index['indisreplident'], [false, 0, '0', 'f'], true)
                || !in_array($index['custom_opclass'], [false, 0, '0', 'f'], true)
                || !in_array($index['custom_collation'], [false, 0, '0', 'f'], true)) {
                throw new RuntimeException('Unsupported affected native PostgreSQL index: ' . static::TABLE . '.' . $index['name']);
            }
        }
        return ['indexes' => $indexes];
    }

    private function affectedIndexes(Table $table): array
    {
        $indexes = [];
        foreach ($table->getIndexes() as $index) {
            if (in_array('items_id', $index->getUnquotedColumns(), true)) {
                $indexes[] = ['name' => $index->getName(), 'columns' => $index->getUnquotedColumns(), 'unique' => $index->isUnique(), 'flags' => $index->getFlags(), 'options' => $index->getOptions()];
            }
        }
        return $indexes;
    }

    /** Capture provenance before the coordinator records any owned work. */
    public function prepare(Connection $connection, ?array $state): array
    {
        if ($state !== null) {
            $this->admit($connection, $state);
            return $state;
        }
        $source = $this->inspectSource($connection);
        $table = $connection->createSchemaManager()->introspectTable(static::TABLE);
        $retained = array_values(array_diff(
            array_map(static fn (Column $column): string => $column->getName(), $table->getColumns()),
            ['items_id']
        ));
        return ['complete' => false, 'phase' => 'audited', 'table' => static::TABLE,
            'identity_hash' => $this->identityHash($connection), 'retained_columns' => $retained,
            'retained_hash' => $this->rowsHash($connection, $retained),
            'column_facts' => $this->columnFacts($connection), 'index_facts' => $this->indexFacts($connection),
            'foreign_facts' => $this->foreignFacts($connection),
            'check_facts' => NativeCheckCatalog::snapshot($connection, static::TABLE)['checks'][static::TABLE] ?? [],
            'affected_indexes' => $source['affected_indexes'], 'native_indexes' => $source['native']['indexes'],
            'items_comment' => $table->getColumn('items_id')->getComment()];
    }

    /** Only the coordinator writes this state into its single existing-ledger receipt. */
    public function applyPrepared(Connection $connection, array $state, callable $save, ?callable $progress): void
    {
        $this->admit($connection, $state);
        if ($state['complete'] === true) {
            return;
        }
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $table = $quote(static::TABLE);
        $manager = $connection->createSchemaManager();
        if ($this->columnGroup($connection) === 'absent') {
            $before = $manager->introspectTable(static::TABLE);
            $after = clone $before;
            foreach (static::OWNERS as [$column]) {
                $after->addColumn($column, 'bigint', ['notnull' => false, 'default' => null]);
            }
            $after->addColumn('opaque_parent_id', 'bigint', ['notnull' => false, 'default' => 0]);
            $sql = $platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after));
            if ($platform instanceof AbstractMySQLPlatform && count($sql) !== 1) {
                throw new RuntimeException('Initial owner column additions must be one atomic MySQL ALTER.');
            }
            foreach ($sql as $statement) {
                $connection->executeStatement($statement);
            }
            $progress && $progress('columns', implode("\n", $sql));
        }
        $this->admit($connection, $state);
        $state['phase'] = 'columns';
        $save($state);
        if (!$this->copyComplete($connection)) {
            if (!$this->copyEmpty($connection)) {
                throw new RuntimeException('Initial owners have a mixed copy; no rows were overwritten.');
            }
            $statement = static::copyStatement($platform);
            $connection->executeStatement($statement);
            $progress && $progress('copy', $statement);
        }
        $this->admit($connection, $state);
        if (!$this->copyComplete($connection)) {
            throw new RuntimeException('Initial owner copy did not match its original identity.');
        }
        $state['phase'] = 'copied';
        $save($state);
        if ($this->constraintGroup($connection) === 'absent') {
            $clauses = [];
            foreach (static::OWNERS as [$column, $target, $mysqlIndex, $postgresIndex]) {
                $index = $platform instanceof AbstractMySQLPlatform ? $mysqlIndex : $postgresIndex;
                $clauses[] = 'ADD INDEX ' . $quote($index) . ' (' . $quote($column) . ')';
                $clauses[] = 'ADD CONSTRAINT ' . $quote($this->foreignName($column)) . ' FOREIGN KEY ('
                    . $quote($column) . ') REFERENCES ' . $quote($target) . ' (' . $quote('id') . ') ON DELETE RESTRICT ON UPDATE NO ACTION';
            }
            $clauses[] = 'ADD CONSTRAINT ' . $quote(static::CHECK) . ' CHECK (' . static::policy($platform)['check'] . ')';
            if ($platform instanceof AbstractMySQLPlatform) {
                // Fixed frozen clauses, not DBAL internal declaration methods or parsed emitted SQL.
                $statement = 'ALTER TABLE ' . $table . ' ' . implode(', ', $clauses);
                $connection->executeStatement($statement);
            } else {
                foreach (static::OWNERS as [$column, $target, , $index]) {
                    $manager->createIndex(new Index($index, [$column]), static::TABLE);
                    $manager->createForeignKey(
                        new ForeignKeyConstraint(
                            [$column],
                            $target,
                            ['id'],
                            $this->foreignName($column),
                            ['onDelete' => 'RESTRICT', 'onUpdate' => 'NO ACTION']
                        ),
                        static::TABLE
                    );
                }
                $statement = 'ALTER TABLE ' . $table . ' ' . end($clauses);
                $connection->executeStatement($statement);
            }
            $progress && $progress('constraints', $statement);
        }
        $this->assertConstraints($connection);
        $this->admit($connection, $state);
        $state['phase'] = 'constrained';
        $save($state);
        if (!$this->isGenerated($connection)) {
            $projection = static::policy($platform)['projection'];
            if ($platform instanceof AbstractMySQLPlatform) {
                $this->assertMysqlTriggerVisibility($connection, (string)$connection->fetchOne('SELECT DATABASE()'));
                if ((int)$connection->fetchOne('SELECT @@SESSION.foreign_key_checks') !== 1) {
                    throw new RuntimeException('Final native admission requires foreign_key_checks=1.');
                }
                // Hidden incoming references are admitted/refused by this single in-place native statement.
                $statement = 'ALTER TABLE ' . $table . ' MODIFY COLUMN ' . $quote('items_id')
                    . ' BIGINT GENERATED ALWAYS AS (' . $projection . ') STORED'
                    . ($state['items_comment'] === null ? '' : ' COMMENT ' . $platform->quoteStringLiteral($state['items_comment']));
                $connection->executeStatement($statement);
            } else {
                $statement = 'ALTER TABLE ' . $table . ' DROP COLUMN ' . $quote('items_id') . ' RESTRICT';
                $connection->executeStatement($statement);
                $connection->executeStatement('ALTER TABLE ' . $table . ' ADD COLUMN ' . $quote('items_id')
                    . ' BIGINT GENERATED ALWAYS AS (' . $projection . ') STORED');
                $connection->executeStatement('COMMENT ON COLUMN ' . $table . '.' . $quote('items_id') . ' IS '
                    . ($state['items_comment'] === null ? 'NULL' : $platform->quoteStringLiteral($state['items_comment'])));
                foreach ($state['native_indexes'] as $index) {
                    $connection->executeStatement($index['definition']);
                    if ($index['comment'] !== null) {
                        $connection->executeStatement('COMMENT ON INDEX ' . $quote($index['name']) . ' IS '
                            . $platform->quoteStringLiteral($index['comment']));
                    }
                }
            }
            $progress && $progress('projection', $statement);
        }
        $this->admit($connection, $state);
        $this->verify($connection, $state);
        $state['phase'] = 'verified';
        $state['complete'] = true;
        $save($state);
    }

    public function verify(Connection $connection, array $state): void
    {
        $this->validateState($state);
        $this->columnGroup($connection, true);
        $this->assertConstraints($connection);
        $differences = NativeSubjectSchema::differences($connection, [static::TABLE => ['items_id' => static::policy($connection->getDatabasePlatform())]]);
        if ($differences) {
            throw new RuntimeException(implode("\n", $differences));
        }
        $this->assertSchema($connection, $state);
        $this->auditCurrent($connection);
    }

    private function validateState(array $state): void
    {
        if (($state['table'] ?? null) !== static::TABLE || !is_bool($state['complete'] ?? null)
            || !is_string($state['identity_hash'] ?? null) || !is_string($state['retained_hash'] ?? null)
            || !is_array($state['retained_columns'] ?? null) || !is_array($state['column_facts'] ?? null)
            || !is_array($state['index_facts'] ?? null) || !is_array($state['native_indexes'] ?? null)
            || !is_array($state['foreign_facts'] ?? null) || !is_array($state['check_facts'] ?? null)
            || !isset($state['column_facts']['id'], $state['column_facts']['itemtype'], $state['column_facts']['items_id'])
            || !array_key_exists('items_comment', $state)
            || ($state['items_comment'] !== null && !is_string($state['items_comment']))
            || !in_array($state['phase'] ?? null, ['audited', 'columns', 'copied', 'constrained', 'verified'], true)
            || ($state['complete'] && $state['phase'] !== 'verified')
            || !preg_match('/\A[a-f0-9]{64}\z/D', $state['identity_hash'])
            || !preg_match('/\A[a-f0-9]{64}\z/D', $state['retained_hash'])) {
            throw new RuntimeException('Invalid initial owner source receipt: ' . static::TABLE);
        }
    }

    private function admit(Connection $connection, array $state): void
    {
        $this->validateState($state);
        $this->assertSchema($connection, $state);
        if ($state['complete'] === true) {
            $this->verify($connection, $state);
            return;
        }
        if ($this->identityHash($connection) !== $state['identity_hash']
            || $this->rowsHash($connection, $state['retained_columns']) !== $state['retained_hash']) {
            throw new RuntimeException('Initial source/payload changed; keep writers drained and reconcile, no rows repaired.');
        }
        if (!$this->isGenerated($connection)) {
            $sourceTable = $connection->createSchemaManager()->introspectTable(static::TABLE);
            if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $this->inspectMysql($connection, (string)$connection->fetchOne('SELECT DATABASE()'), $sourceTable);
            } else {
                $this->inspectPostgres($connection, $sourceTable);
            }
        }
        $this->auditCurrent($connection);
        $columns = $this->columnGroup($connection);
        $constraints = $this->constraintGroup($connection);
        if ($columns === 'absent') {
            if ($constraints !== 'absent' || $this->isGenerated($connection)) {
                throw new RuntimeException('Initial owned constraints/projection precede owning columns.');
            }
            $this->inspectSource($connection);
            return;
        }
        if (!$this->copyComplete($connection) && !$this->copyEmpty($connection)) {
            throw new RuntimeException('Initial copy is neither globally empty nor globally complete.');
        }
        if ($constraints === 'present') {
            if (!$this->copyComplete($connection)) {
                throw new RuntimeException('Initial constraints require complete matching copy.');
            }
            $this->assertConstraints($connection);
        }
        if ($this->isGenerated($connection)) {
            if ($constraints !== 'present') {
                throw new RuntimeException('Initial generated projection lacks owned native constraints.');
            }
            $this->verify($connection, $state);
        }
    }

    private function foreignName(string $column): string
    {
        return 'fk_' . substr(static::TABLE, 5) . '_' . $column;
    }

    private function isGenerated(Connection $connection): bool
    {
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return MySQLGeneratedColumnInspection::isGenerated($connection, (string)$connection->fetchOne('SELECT DATABASE()'), static::TABLE, 'items_id');
        }
        return $connection->fetchOne("SELECT attgenerated FROM pg_catalog.pg_attribute WHERE attrelid=to_regclass(?) AND attname='items_id' AND NOT attisdropped", [static::TABLE]) === 's';
    }

    private function columnGroup(Connection $connection, bool $required = false): string
    {
        $table = $connection->createSchemaManager()->introspectTable(static::TABLE);
        if (!$table->hasColumn('items_id')) {
            throw new RuntimeException('Missing original identity is external damage, not initial adoption resume.');
        }
        $columns = [...array_column(static::OWNERS, 0), 'opaque_parent_id'];
        $present = 0;
        foreach ($columns as $column) {
            if (!$table->hasColumn($column)) {
                continue;
            }
            ++$present;
            $field = $table->getColumn($column);
            if (Type::lookupName($field->getType()) !== 'bigint' || $field->getNotnull() || $field->getUnsigned()
                || $field->getAutoincrement() || ($column === 'opaque_parent_id'
                    ? (string)$field->getDefault() !== '0' : $field->getDefault() !== null)) {
                throw new RuntimeException('Conflicting initial owner column: ' . static::TABLE . '.' . $column);
            }
        }
        if (($present !== 0 && $present !== count($columns)) || ($required && $present === 0)) {
            throw new RuntimeException('Initial owning columns are not one complete atomic group.');
        }
        return $present === 0 ? 'absent' : 'present';
    }

    private function constraintGroup(Connection $connection): string
    {
        $table = $connection->createSchemaManager()->introspectTable(static::TABLE);
        $present = (int)isset(NativeCheckCatalog::snapshot($connection, static::TABLE)['checks'][static::TABLE][static::CHECK]);
        foreach (static::OWNERS as [$column, , $mysqlIndex, $postgresIndex]) {
            $present += (int)$table->hasIndex($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? $mysqlIndex : $postgresIndex);
            $present += (int)$table->hasForeignKey($this->foreignName($column));
        }
        if ($present !== 0 && $present !== 1 + 2 * count(static::OWNERS)) {
            throw new RuntimeException('Initial constraints are not one complete atomic group.');
        }
        return $present === 0 ? 'absent' : 'present';
    }

    private function copyComplete(Connection $connection): bool
    {
        $known = array_keys(static::OWNERS);
        foreach ($connection->executeQuery('SELECT * FROM ' . $connection->quoteIdentifier(static::TABLE) . ' ORDER BY id')->iterateAssociative() as $row) {
            foreach (static::OWNERS as $literal => [$column]) {
                $expected = $row['itemtype'] === $literal && (int)$row['items_id'] > 0 ? (string)$row['items_id'] : null;
                if (($row[$column] === null ? null : (string)$row[$column]) !== $expected) {
                    return false;
                }
            }
            $expected = in_array($row['itemtype'], $known, true) ? null : (string)$row['items_id'];
            if (($row['opaque_parent_id'] === null ? null : (string)$row['opaque_parent_id']) !== $expected) {
                return false;
            }
        }
        return true;
    }

    private function copyEmpty(Connection $connection): bool
    {
        foreach ($connection->executeQuery('SELECT * FROM ' . $connection->quoteIdentifier(static::TABLE) . ' ORDER BY id')->iterateAssociative() as $row) {
            foreach (static::OWNERS as [$column]) {
                if ($row[$column] !== null) {
                    return false;
                }
            }
            if ((string)$row['opaque_parent_id'] !== '0') {
                return false;
            }
        }
        return true;
    }

    private function identityHash(Connection $connection): string
    {
        $hash = hash_init('sha256');
        foreach ($connection->executeQuery('SELECT id, itemtype, items_id FROM ' . $connection->quoteIdentifier(static::TABLE) . ' ORDER BY id')->iterateAssociative() as $row) {
            hash_update($hash, json_encode([(string)$row['id'], $row['itemtype'], $row['items_id'] === null ? null : (string)$row['items_id']], JSON_THROW_ON_ERROR) . "\n");
        }
        return hash_final($hash);
    }

    private function rowsHash(Connection $connection, array $columns): string
    {
        $hash = hash_init('sha256');
        $names = array_map($connection->quoteIdentifier(...), $columns);
        foreach ($connection->executeQuery('SELECT ' . implode(', ', $names) . ' FROM ' . $connection->quoteIdentifier(static::TABLE) . ' ORDER BY id')->iterateAssociative() as $row) {
            hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
        }
        return hash_final($hash);
    }

    private function columnFacts(Connection $connection): array
    {
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $rows = $connection->fetchAllAssociative(
                'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value, EXTRA '
                . 'AS extra, COLUMN_COMMENT AS comment, COLLATION_NAME AS collation FROM information_schema.COLUMNS WHERE '
                . 'TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY COLUMN_NAME',
                [static::TABLE]
            );
        } else {
            $rows = $connection->fetchAllAssociative(
                "SELECT a.attname AS name, format_type(a.atttypid,a.atttypmod) AS type, a.attnotnull AS not_null, a.attidentity AS "
                . "identity, a.attgenerated AS generated, pg_get_expr(d.adbin,d.adrelid) AS default_value, "
                . "col_description(a.attrelid,a.attnum) AS comment, a.attcollation::text AS collation FROM pg_catalog.pg_attribute a "
                . "LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=to_regclass(?) AND "
                . "a.attnum>0 AND NOT a.attisdropped ORDER BY a.attname",
                [static::TABLE]
            );
        }
        return array_column($rows, null, 'name');
    }

    private function indexFacts(Connection $connection): array
    {
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $rows = $connection->fetchAllAssociative('SHOW INDEX FROM ' . $connection->quoteIdentifier(static::TABLE));
            $facts = [];
            foreach ($rows as $row) {
                unset($row['Cardinality']);
                $facts[$row['Key_name']][(int)$row['Seq_in_index']] = $row;
            }
            foreach ($facts as &$parts) {
                ksort($parts);
            }
            unset($parts);
            ksort($facts);
            return $facts;
        }
        $rows = $connection->fetchAllAssociative(
            "SELECT ic.relname AS name, pg_get_indexdef(i.indexrelid) AS definition, obj_description(i.indexrelid,'pg_class') "
            . "AS comment, i.indisvalid, i.indisready, i.indislive FROM pg_catalog.pg_index i JOIN pg_catalog.pg_class ic ON "
            . "ic.oid=i.indexrelid WHERE i.indrelid=to_regclass(?) ORDER BY ic.relname",
            [static::TABLE]
        );
        return array_column($rows, null, 'name');
    }

    private function foreignFacts(Connection $connection): array
    {
        $facts = [];
        foreach ($connection->createSchemaManager()->introspectTable(static::TABLE)->getForeignKeys() as $foreign) {
            $facts[$foreign->getName()] = [$foreign->getUnquotedLocalColumns(), $foreign->getForeignTableName(),
                $foreign->getUnquotedForeignColumns(), $foreign->getOptions()];
            if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $facts[$foreign->getName()][] = $connection->fetchAllAssociative(
                    'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, '
                    . 'r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON '
                    . 'r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME '
                    . 'WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.CONSTRAINT_NAME=? ORDER BY k.ORDINAL_POSITION',
                    [static::TABLE, $foreign->getName()]
                );
            } else {
                $facts[$foreign->getName()][] = $connection->fetchAssociative(
                    "SELECT convalidated, condeferrable, condeferred, confdeltype, confupdtype, conkey::text AS columns, confkey::text "
                    . "AS target_columns, confrelid::regclass::text AS target FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) "
                    . "AND conname=? AND contype='f'",
                    [static::TABLE, $foreign->getName()]
                );
            }
        }
        ksort($facts);
        return $facts;
    }

    private function assertSchema(Connection $connection, array $state): void
    {
        $columns = $this->columnFacts($connection);
        if (!isset($columns['items_id'])) {
            throw new RuntimeException('Missing original items_id is external damage, not a supported retry.');
        }
        $generated = $this->isGenerated($connection);
        foreach ($state['column_facts'] as $name => $fact) {
            if ($name === 'items_id' && $generated) {
                continue;
            }
            if (($columns[$name] ?? null) !== $fact) {
                throw new RuntimeException('Original column facts changed: ' . static::TABLE . '.' . $name);
            }
        }
        if ($generated) {
            $field = $connection->createSchemaManager()->introspectTable(static::TABLE)->getColumn('items_id');
            if (Type::lookupName($field->getType()) !== 'bigint' || $field->getNotnull() || $field->getUnsigned()
                || $field->getAutoincrement() || $field->getDefault() !== null || $field->getComment() !== $state['items_comment']) {
                throw new RuntimeException('Changed final generated identity shape/comment: ' . static::TABLE);
            }
        }
        $indexes = $this->indexFacts($connection);
        foreach ($state['index_facts'] as $name => $fact) {
            $actual = $indexes[$name] ?? null;
            if ($generated && $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform && is_array($actual)) {
                foreach ($fact as $position => $part) {
                    // SHOW INDEX reports the column's intended new nullable shape, not an index declaration change.
                    if (($part['Column_name'] ?? null) === 'items_id' && ($part['Null'] ?? null) === ''
                        && ($actual[$position]['Column_name'] ?? null) === 'items_id'
                        && ($actual[$position]['Null'] ?? null) === 'YES') {
                        $actual[$position]['Null'] = '';
                    }
                }
            }
            if ($actual !== $fact) {
                throw new RuntimeException('Original index facts changed: ' . static::TABLE . '.' . $name);
            }
        }
        $checks = NativeCheckCatalog::snapshot($connection, static::TABLE)['checks'][static::TABLE] ?? [];
        foreach ($state['check_facts'] as $name => $fact) {
            if (($checks[$name] ?? null) !== $fact) {
                throw new RuntimeException('Original native CHECK facts changed: ' . static::TABLE . '.' . $name);
            }
        }
        $foreign = $this->foreignFacts($connection);
        foreach ($state['foreign_facts'] as $name => $fact) {
            if (($foreign[$name] ?? null) !== $fact) {
                throw new RuntimeException('Original foreign key facts changed: ' . static::TABLE . '.' . $name);
            }
        }
    }

    /** Native CHECK/projection proof is deliberately separate from exact owning FK proof. */
    private function assertConstraints(Connection $connection): void
    {
        if ($this->constraintGroup($connection) !== 'present') {
            throw new RuntimeException('Missing complete initial owning constraints.');
        }
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        if ($mysql && (int)$connection->fetchOne('SELECT @@SESSION.foreign_key_checks') !== 1) {
            throw new RuntimeException('Initial ownership requires active native foreign key enforcement.');
        }
        $table = $connection->createSchemaManager()->introspectTable(static::TABLE);
        $nativeIndexes = $mysql ? $this->indexFacts($connection) : [];
        foreach (static::OWNERS as [$column, $target, $mysqlIndex, $postgresIndex]) {
            $index = $table->getIndex($mysql ? $mysqlIndex : $postgresIndex);
            if ($index->getUnquotedColumns() !== [$column] || $index->isUnique()) {
                throw new RuntimeException('Conflicting initial owning index: ' . $index->getName());
            }
            $foreign = $table->getForeignKey($this->foreignName($column));
            if ($foreign->getUnquotedLocalColumns() !== [$column] || $foreign->getUnquotedForeignColumns() !== ['id']
                || $foreign->getForeignTableName() !== $target) {
                throw new RuntimeException('Conflicting initial owning FK structure: ' . $this->foreignName($column));
            }
            if ($mysql) {
                $nativeIndex = $nativeIndexes[$mysqlIndex] ?? [];
                if (count($nativeIndex) !== 1 || ($nativeIndex[1]['Column_name'] ?? null) !== $column
                    || (int)($nativeIndex[1]['Non_unique'] ?? 0) !== 1 || ($nativeIndex[1]['Index_type'] ?? null) !== 'BTREE'
                    || ($nativeIndex[1]['Sub_part'] ?? null) !== null || ($nativeIndex[1]['Expression'] ?? null) !== null
                    || ($nativeIndex[1]['Visible'] ?? 'YES') !== 'YES' || ($nativeIndex[1]['Ignored'] ?? 'NO') !== 'NO') {
                    throw new RuntimeException('Changed native initial owning index: ' . $mysqlIndex);
                }
                $rows = $connection->fetchAllAssociative(
                    'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, '
                    . 'r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON '
                    . 'r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME '
                    . 'WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.CONSTRAINT_NAME=? ORDER BY k.ORDINAL_POSITION',
                    [static::TABLE, $this->foreignName($column)]
                );
                $schema = (string)$connection->fetchOne('SELECT DATABASE()');
                if (count($rows) !== 1 || $rows[0]['COLUMN_NAME'] !== $column || $rows[0]['REFERENCED_TABLE_SCHEMA'] !== $schema
                    || $rows[0]['REFERENCED_TABLE_NAME'] !== $target || $rows[0]['REFERENCED_COLUMN_NAME'] !== 'id'
                    || !in_array($rows[0]['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true)
                    || !in_array($rows[0]['UPDATE_RULE'], ['RESTRICT', 'NO ACTION'], true)) {
                    throw new RuntimeException('Changed native initial owning FK: ' . $this->foreignName($column));
                }
                foreach ([static::TABLE, $target] as $engineTable) {
                    if (strcasecmp((string)$connection->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$engineTable]), 'InnoDB') !== 0) {
                        throw new RuntimeException('Initial native FK requires InnoDB: ' . $engineTable);
                    }
                }
            } else {
                $nativeIndex = $connection->fetchAssociative(
                    "SELECT am.amname, i.indisunique, i.indisvalid, i.indisready, i.indislive, i.indnkeyatts, i.indnatts, "
                    . "i.indoption::text AS options, i.indexprs IS NULL AS plain, i.indpred IS NULL AS unfiltered FROM "
                    . "pg_catalog.pg_index i JOIN pg_catalog.pg_class x ON x.oid=i.indexrelid JOIN pg_catalog.pg_am am ON am.oid=x.relam "
                    . "WHERE i.indrelid=to_regclass(?) AND x.relname=?",
                    [static::TABLE, $postgresIndex]
                );
                if (!is_array($nativeIndex) || $nativeIndex['amname'] !== 'btree'
                    || !in_array($nativeIndex['indisunique'], [false, 0, '0', 'f'], true)
                    || (int)$nativeIndex['indnkeyatts'] !== 1 || (int)$nativeIndex['indnatts'] !== 1 || $nativeIndex['options'] !== '0') {
                    throw new RuntimeException('Changed native initial owning index: ' . $postgresIndex);
                }
                foreach (['indisvalid', 'indisready', 'indislive', 'plain', 'unfiltered'] as $fact) {
                    if (!in_array($nativeIndex[$fact], [true, 1, '1', 't'], true)) {
                        throw new RuntimeException('Invalid native initial owning index: ' . $postgresIndex);
                    }
                }
                $row = $connection->fetchAssociative(
                    "SELECT convalidated, condeferrable, condeferred, confdeltype, confupdtype, confrelid::regclass::text AS target "
                    . "FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND conname=? AND contype='f'",
                    [static::TABLE, $this->foreignName($column)]
                );
                if (!is_array($row) || !in_array($row['convalidated'], [true, 1, '1', 't'], true)
                    || !in_array($row['condeferrable'], [false, 0, '0', 'f'], true) || !in_array($row['condeferred'], [false, 0, '0', 'f'], true)
                    || $row['confdeltype'] !== 'r' || $row['confupdtype'] !== 'a' || $row['target'] !== $target) {
                    throw new RuntimeException('Changed native validated/immediate initial FK: ' . $this->foreignName($column));
                }
            }
        }
        $differences = NativeSubjectSchema::differences($connection, [static::TABLE => ['items_id' => static::policy($connection->getDatabasePlatform())]], checksOnly: true);
        if ($differences) {
            throw new RuntimeException(implode("\n", $differences));
        }
    }

    private function auditCurrent(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $kind = $platform instanceof AbstractMySQLPlatform ? 'CAST(n.itemtype AS BINARY)' : 'n.itemtype';
        foreach (static::OWNERS as $literal => [, $target]) {
            $rows = $connection->fetchAllAssociative(
                'SELECT n.id, n.itemtype, n.items_id FROM ' . $platform->quoteIdentifier(static::TABLE)
                . ' n LEFT JOIN ' . $platform->quoteIdentifier($target) . ' p ON p.id=n.items_id WHERE n.itemtype IS NULL OR n.items_id IS NULL OR ('
                . $kind . ' IN (' . $platform->quoteStringLiteral($literal) . ') AND (n.items_id<0 OR (n.items_id>0 AND p.id IS NULL))) ORDER BY n.id LIMIT 5'
            );
            if ($rows) {
                throw new RuntimeException('Invalid current initial owning identity: ' . json_encode($rows, JSON_THROW_ON_ERROR));
            }
        }
    }
}
