<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\Frozen\ComponentExpansionV1;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\Migration\Frozen\V1\SingleOpenParentAdoption;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\V220\IncomingProjectionReferences;
use itsmng\Database\NativeCheckCatalog;
use itsmng\Database\NativeSubjectSchema;
use itsmng\Database\SubjectPolicyExpression;
use RuntimeException;

/** Frozen additive Component expansion ownership; apply only with application writers stopped and drained. */
abstract class OpenParentExpansion
{
    public const TABLE = '';
    public const PREDECESSOR = '';
    protected const PHASE = '';
    protected const CHECK = '';
    protected const BRIDGE = '';
    protected const FOREIGN_PREFIX = '';
    protected const DISCRIMINATOR_NULLABLE = false;
    protected const DISCRIMINATOR_LENGTH = 100;
    protected const OWNERS = [];

    private static function predecessor(): SingleOpenParentAdoption
    {
        $class = static::PREDECESSOR;
        return new $class();
    }

    private static function predecessorPolicy(AbstractPlatform $platform): array
    {
        $class = static::PREDECESSOR;
        return $class::policy($platform);
    }

    /** Exact frozen declarations, never current entity metadata or configured affinity. */
    public static function policy(AbstractPlatform $platform, bool $bridge = false): array
    {
        $q = $platform->quoteIdentifier(...);
        $raw = $q('itemtype');
        $kind = $platform instanceof AbstractMySQLPlatform ? 'CAST(' . $raw . ' AS BINARY)' : $raw;
        $opaque = $q('opaque_parent_id');
        $branches = $cases = $unselected = [];
        $types = ['opaque_parent_id' => 'bigint'];
        foreach (static::OWNERS as $choice => [$column]) {
            $owner = $q($column);
            $literal = $platform->quoteStringLiteral($choice);
            $selected = $kind . ' IN (' . $literal . ')';
            $cases[] = 'WHEN ' . $selected . ' THEN COALESCE(' . $owner . ', 0)';
            $branch = [$selected, '(' . $owner . ' IS NULL OR ' . $owner . ' > 0)'];
            if (static::DISCRIMINATOR_NULLABLE) {
                array_unshift($branch, $raw . ' IS NOT NULL');
            }
            $branch[] = $bridge && $choice !== 'Computer'
                ? '(' . $opaque . ' IS NULL OR (' . $owner . ' IS NULL AND ' . $opaque . ' = 0) OR (' . $owner . ' IS NOT NULL AND ' . $owner . ' > 0 AND ' . $opaque . ' = ' . $owner . '))'
                : $opaque . ' IS NULL';
            foreach (static::OWNERS as $other => [$otherColumn]) {
                if ($other !== $choice) {
                    $branch[] = $q($otherColumn) . ' IS NULL';
                }
            }
            $branches[] = '(' . implode(' AND ', $branch) . ')';
            $unselected[] = $kind . ' NOT IN (' . $literal . ')';
            $types[$column] = 'bigint';
        }
        $unknown = [static::DISCRIMINATOR_NULLABLE
            ? '(' . $raw . ' IS NULL OR (' . implode(' AND ', $unselected) . '))'
            : '(' . implode(' AND ', $unselected) . ')'];
        foreach (static::OWNERS as [$column]) {
            $unknown[] = $q($column) . ' IS NULL';
        }
        $unknown[] = $opaque . ' IS NOT NULL';
        $branches[] = '(' . implode(' AND ', $unknown) . ')';
        return ['projection' => 'CASE ' . implode(' ', $cases) . ' ELSE ' . $opaque . ' END',
            'constraint' => $bridge ? static::BRIDGE : static::CHECK, 'check' => implode(' OR ', $branches),
            'discriminators' => ['itemtype'], 'integer_types' => $types,
            'string_selections' => ['itemtype' => array_keys(static::OWNERS)], 'open_string_fallback' => true];
    }

    public function plan(Connection $connection): array
    {
        $state = Ledger::state($connection, static::PHASE);
        if ($state === null) {
            static::predecessor()->verify($connection);
            $table = $connection->createSchemaManager()->introspectTable(static::TABLE);
            foreach (static::OWNERS as $choice => [$column]) {
                if ($choice !== 'Computer' && $table->hasColumn($column)) {
                    throw new RuntimeException('Component expansion expansion requires its genuine Computer-only predecessor, not unowned added columns.');
                }
            }
            if (isset(NativeCheckCatalog::snapshot($connection, static::TABLE)['checks'][static::TABLE][static::BRIDGE])) {
                throw new RuntimeException('Unowned Component expansion expansion CHECK before its source checkpoint.');
            }
            $this->dependencies($connection);
        } else {
            $this->source($connection, $state);
        }
        $this->columns($connection);
        $this->existingConstraints($connection);
        if ($state !== null && ($state['complete'] ?? false) !== true) {
            $this->admission($connection);
        }
        $this->audit($connection);
        return [static::TABLE => ['adopted_kinds' => array_keys(static::OWNERS), 'opaque_other_kinds' => true,
            'requires_stopped_and_drained_writers' => true, 'checkpoint' => $state['phase'] ?? null,
            'stages' => ['columns', 'constraints', 'copy', 'bridge', 'projection', 'clear', 'final', 'verified']]];
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        $this->plan($connection);
        if ((Ledger::state($connection, static::PHASE)['complete'] ?? false) === true) {
            $this->verify($connection);
            return;
        }
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        if ($mysql && $connection->isTransactionActive()) {
            throw new RuntimeException('Component expansion expansion must run outside a MySQL application transaction with writers stopped.');
        }
        $run = function () use ($connection, $progress): void {
            $platform = $connection->getDatabasePlatform();
            $manager = $connection->createSchemaManager();
            $state = Ledger::state($connection, static::PHASE);
            if ($state === null) {
                $table = $manager->introspectTable(static::TABLE);
                $indexes = [];
                foreach ($table->getIndexes() as $index) {
                    if (in_array('items_id', $index->getUnquotedColumns(), true)) {
                        $indexes[] = ['name' => $index->getName(), 'columns' => $index->getUnquotedColumns(),
                            'unique' => $index->isUnique(), 'flags' => $index->getFlags(), 'options' => $index->getOptions()];
                    }
                }
                $retained = array_values(array_diff(
                    array_map(static fn (Column $column): string => $column->getName(), $table->getColumns()),
                    ['items_id', 'opaque_parent_id']
                ));
                $state = ['complete' => false, 'phase' => 'audited', 'source_hash' => $this->identityHash($connection, 'items_id'),
                    'retained_columns' => $retained, 'retained_hash' => $this->retainedHash($connection, $retained),
                    'indexes' => $indexes, 'items_comment' => $table->getColumn('items_id')->getComment()];
                Ledger::save($connection, static::PHASE, $state);
            }
            $this->source($connection, $state);
            $checks = NativeCheckCatalog::snapshot($connection, static::TABLE)['checks'][static::TABLE] ?? [];
            if ($this->finalCheck($connection) && !isset($checks[static::BRIDGE])
                && $this->isProjection($connection, static::policy($platform)['projection'])) {
                $this->verify($connection);
                $state['phase'] = 'verified';
                $state['complete'] = true;
                Ledger::save($connection, static::PHASE, $state);
                return;
            }
            foreach (static::OWNERS as $choice => [$column]) {
                if ($choice === 'Computer') {
                    continue;
                }
                $before = $manager->introspectTable(static::TABLE);
                if (!$before->hasColumn($column)) {
                    $after = clone $before;
                    $after->addColumn($column, 'bigint', ['notnull' => false, 'default' => null]);
                    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
                        $connection->executeStatement($sql);
                        $progress && $progress('columns', $sql);
                    }
                }
            }
            $this->columns($connection);
            foreach (static::OWNERS as [$column, $target, $suffix]) {
                $before = $manager->introspectTable(static::TABLE);
                $after = clone $before;
                $index = static::TABLE . '_' . $suffix . '_owner';
                $foreign = static::FOREIGN_PREFIX . $column;
                if (!$after->hasIndex($index)) {
                    $after->addIndex([$column], $index);
                } elseif ($after->getIndex($index)->getUnquotedColumns() !== [$column] || $after->getIndex($index)->isUnique()) {
                    throw new RuntimeException('Conflicting Component expansion owning index: ' . $index);
                }
                if (!$after->hasForeignKey($foreign)) {
                    $after->addForeignKeyConstraint($target, [$column], ['id'], ['onDelete' => 'RESTRICT'], $foreign);
                } else {
                    $this->foreign($connection, $after->getForeignKey($foreign), $column, $target);
                }
                foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
                    $connection->executeStatement($sql);
                    $progress && $progress('constraints', $sql);
                }
                $this->foreign($connection, $manager->introspectTable(static::TABLE)->getForeignKey($foreign), $column, $target);
            }
            $oldProjection = $this->isProjection($connection, static::predecessorPolicy($platform)['projection']);
            if ($oldProjection) {
                $this->native($connection, static::predecessorPolicy($platform));
                $set = [];
                foreach (static::OWNERS as $choice => [$column]) {
                    if ($choice !== 'Computer') {
                        $kind = $platform instanceof AbstractMySQLPlatform ? 'CAST(itemtype AS BINARY)' : 'itemtype';
                        $set[] = $column . ' = CASE WHEN ' . $kind . ' IN (' . $platform->quoteStringLiteral($choice) . ') AND opaque_parent_id > 0 THEN opaque_parent_id ELSE NULL END';
                    }
                }
                $this->copiedOrEmpty($connection);
                $sql = 'UPDATE ' . static::TABLE . ' SET ' . implode(', ', $set);
                $connection->executeStatement($sql);
                if ($this->identityHash($connection, '(' . static::policy($platform)['projection'] . ')') !== $state['source_hash']) {
                    throw new RuntimeException('Component expansion copied ownership changed logical source identity.');
                }
                $state['phase'] = 'copied';
                Ledger::save($connection, static::PHASE, $state);
                $progress && $progress('copy', $sql);
            } elseif (!$this->isProjection($connection, static::policy($platform)['projection'])) {
                throw new RuntimeException('Changed Component expansion projection outside its frozen expansion phases.');
            }
            $this->source($connection, $state);
            $this->check($connection, static::policy($platform, bridge: true), $progress);
            if ($oldProjection) {
                $this->projection($connection, $state, $progress);
            }
            $this->native($connection, static::policy($platform, bridge: true));
            $catalog = NativeCheckCatalog::snapshot($connection, static::TABLE);
            if (isset($catalog['checks'][static::TABLE][static::CHECK]) && !$this->finalCheck($connection)) {
                $this->native($connection, static::predecessorPolicy($platform), checksOnly: true);
                $sql = 'ALTER TABLE ' . static::TABLE . ($platform instanceof MySQLPlatform ? ' DROP CHECK ' : ' DROP CONSTRAINT ') . static::CHECK;
                $connection->executeStatement($sql);
                $progress && $progress('bridge', $sql);
            }
            $kind = $platform instanceof AbstractMySQLPlatform ? 'CAST(itemtype AS BINARY)' : 'itemtype';
            foreach (static::OWNERS as $choice => [$column]) {
                if ($choice !== 'Computer') {
                    $sql = 'UPDATE ' . static::TABLE . ' SET opaque_parent_id = NULL WHERE ' . $kind . ' IN (' . $platform->quoteStringLiteral($choice) . ')';
                    $connection->executeStatement($sql);
                    $progress && $progress('clear', $sql);
                }
            }
            $this->source($connection, $state);
            $this->check($connection, static::policy($platform), $progress);
            $this->native($connection, static::policy($platform));
            $sql = 'ALTER TABLE ' . static::TABLE . ($platform instanceof MySQLPlatform ? ' DROP CHECK ' : ' DROP CONSTRAINT ') . static::BRIDGE;
            $connection->executeStatement($sql);
            $progress && $progress('final', $sql);
            $this->verify($connection);
            $state['phase'] = 'verified';
            $state['complete'] = true;
            Ledger::save($connection, static::PHASE, $state);
        };
        if ($mysql) {
            $run();
        } else {
            $connection->transactional($run);
        }
    }

    /** Refuse named index/FK conflicts before the first owned column or other DDL. */
    private function existingConstraints(Connection $connection): void
    {
        $table = $connection->createSchemaManager()->introspectTable(static::TABLE);
        foreach (static::OWNERS as [$column, $target, $suffix]) {
            $index = static::TABLE . '_' . $suffix . '_owner';
            $foreign = static::FOREIGN_PREFIX . $column;
            if ($table->hasIndex($index)
                && ($table->getIndex($index)->getUnquotedColumns() !== [$column] || $table->getIndex($index)->isUnique())) {
                throw new RuntimeException('Conflicting Component expansion owning index: ' . $index);
            }
            if ($table->hasForeignKey($foreign)) {
                $this->foreign($connection, $table->getForeignKey($foreign), $column, $target);
            }
        }
    }

    private function check(Connection $connection, array $policy, ?callable $progress): void
    {
        $checks = NativeCheckCatalog::snapshot($connection, static::TABLE)['checks'][static::TABLE] ?? [];
        if (!isset($checks[$policy['constraint']])) {
            $sql = 'ALTER TABLE ' . static::TABLE . ' ADD CONSTRAINT ' . $policy['constraint'] . ' CHECK (' . $policy['check'] . ')';
            $connection->executeStatement($sql);
            $progress && $progress('bridge', $sql);
        }
        $this->native($connection, $policy, checksOnly: true);
    }

    private function native(Connection $connection, array $policy, bool $checksOnly = false): void
    {
        $differences = NativeSubjectSchema::differences($connection, [static::TABLE => ['items_id' => $policy]], checksOnly: $checksOnly);
        if ($differences) {
            throw new RuntimeException(implode("\n", $differences));
        }
    }

    private function finalCheck(Connection $connection): bool
    {
        $check = NativeCheckCatalog::snapshot($connection, static::TABLE)['checks'][static::TABLE][static::CHECK] ?? null;
        return $check !== null && SubjectPolicyExpression::equivalent(
            static::policy($connection->getDatabasePlatform())['check'],
            $check['clause'],
            !$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            NativeCheckCatalog::snapshot($connection, static::TABLE)['ansi_quotes'] ?? false,
            integerTypes: static::policy($connection->getDatabasePlatform())['integer_types'],
            stringSelections: static::policy($connection->getDatabasePlatform())['string_selections']
        );
    }

    private function isProjection(Connection $connection, string $expected): bool
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $row = $connection->fetchAssociative('SELECT GENERATION_EXPRESSION AS expression, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [static::TABLE, 'items_id']);
            $stored = is_array($row) && preg_match('/(?:^|\s)STORED GENERATED(?:\s|$)/', $row['extra']) === 1;
        } else {
            $row = $connection->fetchAssociative("SELECT pg_get_expr(d.adbin,d.adrelid) AS expression, a.attgenerated AS extra FROM pg_catalog.pg_attribute a JOIN pg_catalog.pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=to_regclass(?) AND a.attname=? AND NOT a.attisdropped", [static::TABLE, 'items_id']);
            $stored = is_array($row) && $row['extra'] === 's';
        }
        $policy = static::policy($platform);
        return $stored && SubjectPolicyExpression::equivalent(
            $expected,
            $row['expression'],
            !$platform instanceof AbstractMySQLPlatform,
            NativeCheckCatalog::snapshot($connection, static::TABLE)['ansi_quotes'] ?? false,
            integerTypes: $policy['integer_types'],
            stringSelections: $policy['string_selections']
        );
    }

    /** Every resumed physical state must have its actual native admission, not only a journal label. */
    private function admission(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $checks = NativeCheckCatalog::snapshot($connection, static::TABLE)['checks'][static::TABLE] ?? [];
        if ($this->isProjection($connection, static::predecessorPolicy($platform)['projection'])) {
            $this->native($connection, static::predecessorPolicy($platform));
            if (isset($checks[static::BRIDGE])) {
                $this->native($connection, static::policy($platform, bridge: true), checksOnly: true);
            }
        } elseif ($this->isProjection($connection, static::policy($platform)['projection'])) {
            if ($this->finalCheck($connection)) {
                $this->native($connection, static::policy($platform));
            } elseif (isset($checks[static::BRIDGE])) {
                $this->native($connection, static::policy($platform, bridge: true));
                if (isset($checks[static::CHECK])) {
                    $this->native($connection, static::predecessorPolicy($platform), checksOnly: true);
                }
            } else {
                throw new RuntimeException('Component expansion final projection has no proven admitting CHECK.');
            }
        } else {
            throw new RuntimeException('Component expansion resume has neither its predecessor nor final native projection.');
        }
    }

    private function columns(Connection $connection, bool $complete = false): void
    {
        $manager = $connection->createSchemaManager();
        $table = $manager->introspectTable(static::TABLE);
        $kind = $table->getColumn('itemtype');
        if (Type::lookupName($kind->getType()) !== 'string' || $kind->getNotnull() !== !static::DISCRIMINATOR_NULLABLE
            || $kind->getLength() !== static::DISCRIMINATOR_LENGTH) {
            throw new RuntimeException('Frozen discriminator nullability and width changed for component expansion.');
        }
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            && (int)$connection->fetchOne('SELECT @@SESSION.foreign_key_checks') !== 1) {
            throw new RuntimeException('Component expansion expansion requires enabled native foreign key checks.');
        }
        foreach (static::OWNERS as [$column, $target]) {
            $id = $manager->introspectTable($target)->getColumn('id');
            if (Type::lookupName($id->getType()) !== 'bigint' || !$id->getNotnull() || $id->getUnsigned()) {
                throw new RuntimeException('Component expansion parent identity must remain signed nonnull bigint: ' . $target);
            }
            if (!$table->hasColumn($column)) {
                if ($complete || $column === 'computers_id') {
                    throw new RuntimeException('Missing Component expansion owning column: ' . $column);
                }
                continue;
            }
            $field = $table->getColumn($column);
            if (Type::lookupName($field->getType()) !== 'bigint' || $field->getNotnull() || $field->getUnsigned()
                || $field->getAutoincrement() || $field->getDefault() !== null) {
                throw new RuntimeException('Conflicting Component expansion owning column: ' . $column);
            }
        }
        foreach (['items_id' => null, 'opaque_parent_id' => 0] as $column => $default) {
            $field = $table->getColumn($column);
            if (Type::lookupName($field->getType()) !== 'bigint' || $field->getNotnull() || $field->getUnsigned() || $field->getAutoincrement()
                || ($default === null ? $field->getDefault() !== null : (string)$field->getDefault() !== '0')) {
                throw new RuntimeException('Conflicting Component expansion compatibility/fallback column: ' . $column);
            }
        }
    }

    private function source(Connection $connection, array $state): void
    {
        if (!is_string($state['source_hash'] ?? null) || !is_string($state['retained_hash'] ?? null)
            || !is_array($state['retained_columns'] ?? null) || !is_array($state['indexes'] ?? null)) {
            throw new RuntimeException('Invalid Component expansion expansion source checkpoint.');
        }
        $this->columns($connection);
        if (($state['complete'] ?? false) !== true
            && ($this->identityHash($connection, 'items_id') !== $state['source_hash']
                || $this->retainedHash($connection, $state['retained_columns']) !== $state['retained_hash'])) {
            throw new RuntimeException('Component expansion rows changed after the source audit; keep writers stopped and reconcile the owned checkpoint. No rows were repaired.');
        }
    }

    private function identityHash(Connection $connection, string $identity): string
    {
        $hash = hash_init('sha256');
        foreach ($connection->executeQuery('SELECT id, itemtype, ' . $identity . ' AS identity FROM ' . static::TABLE . ' ORDER BY id')->iterateAssociative() as $row) {
            hash_update($hash, json_encode([(string)$row['id'], $row['itemtype'], $row['identity'] === null ? null : (string)$row['identity']], JSON_THROW_ON_ERROR) . "\n");
        }
        return hash_final($hash);
    }

    private function retainedHash(Connection $connection, array $columns): string
    {
        $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
        $hash = hash_init('sha256');
        foreach ($connection->executeQuery('SELECT ' . implode(', ', array_map($quote, $columns)) . ' FROM ' . static::TABLE . ' ORDER BY id')->iterateAssociative() as $row) {
            hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
        }
        return hash_final($hash);
    }

    private function audit(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $kind = $platform instanceof AbstractMySQLPlatform ? 'CAST(n.itemtype AS BINARY)' : 'n.itemtype';
        foreach (static::OWNERS as $choice => [, $target]) {
            $rows = $connection->fetchAllAssociative('SELECT n.id, n.itemtype, n.items_id FROM ' . static::TABLE . ' n LEFT JOIN ' . $target
                . ' p ON p.id=n.items_id WHERE n.items_id IS NULL OR (' . $kind . ' IN (' . $platform->quoteStringLiteral($choice)
                . ') AND (n.items_id < 0 OR (n.items_id > 0 AND p.id IS NULL))) ORDER BY n.id LIMIT 5');
            if ($rows) {
                throw new RuntimeException('Invalid Component expansion ' . $choice . ' negative/orphan/null source identity; no rows repaired: ' . json_encode($rows, JSON_THROW_ON_ERROR));
            }
        }
    }

    private function copiedOrEmpty(Connection $connection): void
    {
        $rows = $connection->executeQuery('SELECT * FROM ' . static::TABLE . ' ORDER BY id')->iterateAssociative();
        $empty = $copied = true;
        foreach ($rows as $row) {
            foreach (static::OWNERS as $choice => [$column]) {
                if ($choice === 'Computer') {
                    continue;
                }
                $actual = $row[$column] === null ? null : (string)$row[$column];
                $expected = $row['itemtype'] === $choice && (int)$row['items_id'] > 0 ? (string)$row['items_id'] : null;
                $empty = $empty && $actual === null;
                $copied = $copied && $actual === $expected;
            }
        }
        if (!$empty && !$copied) {
            throw new RuntimeException('Component expansion owning copy is neither the original empty state nor the complete matching copy; no data was overwritten.');
        }
    }

    /** Source-generated identity dependencies must be explicitly preserved, never dropped by CASCADE. */
    private function dependencies(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $schema = $connection->fetchOne($platform instanceof AbstractMySQLPlatform ? 'SELECT DATABASE()' : 'SELECT current_schema()');
        if ((new IncomingProjectionReferences($connection))->has($schema, static::TABLE)) {
            throw new RuntimeException('Incoming Component expansion logical identity FK requires a separate explicit migration.');
        }
        $table = $connection->createSchemaManager()->introspectTable(static::TABLE);
        foreach ($table->getForeignKeys() as $foreign) {
            if (in_array('items_id', $foreign->getLocalColumns(), true)) {
                throw new RuntimeException('Outgoing Component expansion logical identity FK requires a separate explicit migration.');
            }
        }
        $catalog = NativeCheckCatalog::snapshot($connection, static::TABLE);
        foreach ($catalog['checks'][static::TABLE] ?? [] as $check) {
            $fields = $check['checked_columns'] ?? [];
            $fields = is_string($fields) ? json_decode($fields, true, flags: JSON_THROW_ON_ERROR) : $fields;
            if ((is_array($fields) && in_array('items_id', $fields, true))
                || ($platform instanceof AbstractMySQLPlatform && preg_match('/\bitems_id\b/i', $check['clause'] ?? ''))) {
                throw new RuntimeException('Custom Component expansion logical identity CHECK requires a separate explicit migration.');
            }
        }
        if (!$platform instanceof AbstractMySQLPlatform && $connection->fetchOne(
            "SELECT COUNT(*) FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_attribute a ON a.attrelid=d.refobjid AND a.attnum=d.refobjsubid "
            . "WHERE a.attrelid=to_regclass(?) AND a.attname='items_id' AND ((d.classid='pg_catalog.pg_attrdef'::regclass AND d.objid NOT IN "
            . "(SELECT oid FROM pg_catalog.pg_attrdef WHERE adrelid=a.attrelid AND adnum=a.attnum)) OR d.classid='pg_catalog.pg_rewrite'::regclass "
            . "OR (d.classid='pg_catalog.pg_class'::regclass AND EXISTS (SELECT 1 FROM pg_catalog.pg_index i WHERE i.indexrelid=d.objid AND (i.indexprs IS NOT NULL OR i.indpred IS NOT NULL))))",
            [static::TABLE]
        ) > 0) {
            throw new RuntimeException('Dependent Component expansion identity expression/view/index requires a separate explicit migration.');
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            foreach ($connection->fetchAllAssociative('SELECT COLUMN_NAME AS name, GENERATION_EXPRESSION AS expression FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [static::TABLE]) as $field) {
                if ($field['name'] !== 'items_id' && preg_match('/\bitems_id\b/i', $field['expression'] ?? '')) {
                    throw new RuntimeException('Dependent Component expansion generated identity requires a separate explicit migration.');
                }
            }
        }
    }

    private function projection(Connection $connection, array $state, ?callable $progress): void
    {
        $platform = $connection->getDatabasePlatform();
        $manager = $connection->createSchemaManager();
        $comment = $state['items_comment'] ?? '';
        $declaration = 'BIGINT GENERATED ALWAYS AS (' . static::policy($platform)['projection'] . ') STORED';
        if ($platform instanceof AbstractMySQLPlatform) {
            if ($comment !== '') {
                $declaration .= ' ' . $platform->getInlineColumnCommentSQL($comment);
            }
            $sql = 'ALTER TABLE ' . static::TABLE . ' MODIFY COLUMN items_id ' . $declaration;
            $connection->executeStatement($sql);
        } else {
            foreach ($state['indexes'] as $index) {
                $connection->executeStatement($platform->getDropIndexSQL($index['name'], static::TABLE));
            }
            $connection->executeStatement('ALTER TABLE ' . static::TABLE . ' DROP COLUMN items_id');
            $sql = 'ALTER TABLE ' . static::TABLE . ' ADD COLUMN items_id ' . $declaration;
            $connection->executeStatement($sql);
            if ($comment !== '') {
                $connection->executeStatement($platform->getCommentOnColumnSQL(static::TABLE, 'items_id', $comment));
            }
            $before = $manager->introspectTable(static::TABLE);
            $after = clone $before;
            foreach ($state['indexes'] as $index) {
                if ($index['unique']) {
                    $after->addUniqueIndex($index['columns'], $index['name'], $index['options']);
                } else {
                    $after->addIndex($index['columns'], $index['name'], $index['flags'], $index['options']);
                }
            }
            foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $statement) {
                $connection->executeStatement($statement);
            }
        }
        $this->native($connection, static::policy($platform, bridge: true));
        $this->source($connection, $state);
        $progress && $progress('projection', $sql);
    }

    private function foreign(Connection $connection, ForeignKeyConstraint $foreign, string $column, string $target): void
    {
        if ($foreign->getLocalColumns() !== [$column] || $foreign->getForeignTableName() !== $target || $foreign->getForeignColumns() !== ['id']
            || !in_array($foreign->onDelete(), [null, 'RESTRICT', 'NO ACTION'], true) || !in_array($foreign->onUpdate(), [null, 'RESTRICT', 'NO ACTION'], true)) {
            throw new RuntimeException('Conflicting Component expansion owning FK: ' . $column);
        }
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $row = $connection->fetchAssociative('SELECT DELETE_RULE AS d, UPDATE_RULE AS u, UNIQUE_CONSTRAINT_SCHEMA AS s, REFERENCED_TABLE_NAME AS t, DATABASE() AS current_schema FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?', [static::TABLE, $foreign->getName()]);
            $valid = (int)$connection->fetchOne('SELECT @@SESSION.foreign_key_checks') === 1 && is_array($row)
                && $row['s'] === $row['current_schema'] && $row['t'] === $target && in_array($row['d'], ['RESTRICT', 'NO ACTION'], true) && in_array($row['u'], ['RESTRICT', 'NO ACTION'], true);
        } else {
            $row = $connection->fetchAssociative("SELECT c.convalidated, c.condeferrable, c.condeferred, c.confdeltype, c.confupdtype, COALESCE(to_jsonb(c)->>'conenforced','true') AS enforced, c.confrelid=to_regclass(?) AS visible_target FROM pg_catalog.pg_constraint c WHERE c.conrelid=to_regclass(?) AND c.conname=? AND c.contype='f'", [$target, static::TABLE, $foreign->getName()]);
            $truth = [true, 1, '1', 't', 'true'];
            $false = [false, 0, '0', 'f'];
            $valid = is_array($row) && in_array($row['convalidated'], $truth, true) && in_array($row['enforced'], $truth, true)
                && in_array($row['visible_target'], $truth, true) && in_array($row['condeferrable'], $false, true) && in_array($row['condeferred'], $false, true)
                && in_array($row['confdeltype'], ['r', 'a'], true) && in_array($row['confupdtype'], ['r', 'a'], true);
        }
        if (!$valid) {
            throw new RuntimeException('Component expansion FK is changed, deferred, unvalidated or unenforced: ' . $column);
        }
    }

    public function verify(Connection $connection): void
    {
        $this->columns($connection, complete: true);
        $this->source($connection, Ledger::state($connection, static::PHASE) ?? []);
        $this->audit($connection);
        $this->native($connection, static::policy($connection->getDatabasePlatform()));
        $table = $connection->createSchemaManager()->introspectTable(static::TABLE);
        foreach (static::OWNERS as [$column, $target, $suffix]) {
            $foreign = static::FOREIGN_PREFIX . $column;
            $index = static::TABLE . '_' . $suffix . '_owner';
            if (!$table->hasForeignKey($foreign) || !$table->hasIndex($index) || $table->getIndex($index)->getUnquotedColumns() !== [$column] || $table->getIndex($index)->isUnique()) {
                throw new RuntimeException('Missing/changed Component expansion owning FK/index: ' . $column);
            }
            $this->foreign($connection, $table->getForeignKey($foreign), $column, $target);
        }
        foreach (Ledger::state($connection, static::PHASE)['indexes'] as $index) {
            if (!$table->hasIndex($index['name']) || $table->getIndex($index['name'])->getUnquotedColumns() !== $index['columns']
                || $table->getIndex($index['name'])->isUnique() !== $index['unique']) {
                throw new RuntimeException('Changed retained Component expansion compatibility index: ' . $index['name']);
            }
        }
        if (isset(NativeCheckCatalog::snapshot($connection, static::TABLE)['checks'][static::TABLE][static::BRIDGE])) {
            throw new RuntimeException('Unexpected remaining Component expansion bridge CHECK.');
        }

    }

}
