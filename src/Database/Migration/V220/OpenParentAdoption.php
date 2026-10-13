<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Types\Type;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\MySQLGeneratedColumnInspection;
use itsmng\Database\NativeCheckCatalog;
use itsmng\Database\NativeSubjectSchema;
use RuntimeException;

/** Direct released-schema adoption of the final 2.2.0 parent branches. */
abstract class OpenParentAdoption
{
    protected const DISCRIMINATOR_NULLABLE = false;
    protected const DISCRIMINATOR_LENGTH = 100;

    /** kind => [column, target table, physical index, foreign key]. */
    protected const OWNERS = [];

    public static function policy(AbstractPlatform $platform): array
    {
        $quote = $platform->quoteIdentifier(...);
        $raw = $quote('itemtype');
        $kind = $platform instanceof AbstractMySQLPlatform ? 'CAST(' . $raw . ' AS BINARY)' : $raw;
        $opaque = $quote('opaque_parent_id');
        $branches = $cases = $unselected = [];
        $types = ['opaque_parent_id' => 'bigint'];
        foreach (static::OWNERS as $choice => [$column]) {
            $owner = $quote($column);
            $selected = $kind . ' IN (' . $platform->quoteStringLiteral($choice) . ')';
            $cases[] = 'WHEN ' . $selected . ' THEN COALESCE(' . $owner . ', 0)';
            $branch = [$selected, '(' . $owner . ' IS NULL OR ' . $owner . ' > 0)', $opaque . ' IS NULL'];
            if (static::DISCRIMINATOR_NULLABLE) {
                array_unshift($branch, $raw . ' IS NOT NULL');
            }
            foreach (static::OWNERS as $other => [$otherColumn]) {
                if ($other !== $choice) {
                    $branch[] = $quote($otherColumn) . ' IS NULL';
                }
            }
            $branches[] = '(' . implode(' AND ', $branch) . ')';
            $unselected[] = $kind . ' NOT IN (' . $platform->quoteStringLiteral($choice) . ')';
            $types[$column] = 'bigint';
        }
        $unknown = [static::DISCRIMINATOR_NULLABLE
            ? '(' . $raw . ' IS NULL OR ' . implode(' AND ', $unselected) . ')'
            : implode(' AND ', $unselected)];
        foreach (static::OWNERS as [$column]) {
            $unknown[] = $quote($column) . ' IS NULL';
        }
        $unknown[] = $opaque . ' IS NOT NULL';
        $branches[] = '(' . implode(' AND ', $unknown) . ')';
        return ['projection' => 'CASE ' . implode(' ', $cases) . ' ELSE ' . $opaque . ' END',
            'constraint' => static::CHECK, 'check' => implode(' OR ', $branches),
            'discriminators' => ['itemtype'], 'integer_types' => $types,
            'string_selections' => ['itemtype' => array_keys(static::OWNERS)], 'open_string_fallback' => true];
    }

    /** Preview never creates receipts, columns or data. */
    public function plan(Connection $connection): array
    {
        $table = $connection->createSchemaManager()->introspectTable(static::TABLE);
        $state = Ledger::state($connection, static::PHASE);
        $this->verifyColumns($connection, legacy: $state === null);
        if ($state === null) {
            if (!$table->hasColumn('items_id') || $this->generated($connection)
                || array_filter(array_keys($this->columns()), $table->hasColumn(...))) {
                throw new RuntimeException(static::LABEL . ' adoption requires the genuine stored legacy identity or its original owned checkpoint.');
            }
            $this->audit($connection, 'items_id');
        } else {
            $this->assertSource($connection, $state);
        }
        return [static::TABLE => ['adopted_kinds' => array_keys(static::OWNERS), 'opaque_other_kinds' => true,
            'stages' => ['columns', 'copy', 'constraints', 'projection', 'verified'],
            'generated' => $this->generated($connection), 'checkpoint' => $state['phase'] ?? null]];
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
            throw new RuntimeException(static::LABEL . ' adoption must run outside a MySQL application transaction.');
        }
        $apply = function () use ($connection, $progress): void {
            $platform = $connection->getDatabasePlatform();
            $manager = $connection->createSchemaManager();
            $state = Ledger::state($connection, static::PHASE);
            if ($state === null) {
                $table = $manager->introspectTable(static::TABLE);
                $schema = $connection->fetchOne($platform instanceof AbstractMySQLPlatform ? 'SELECT DATABASE()' : 'SELECT current_schema()');
                if ((new IncomingProjectionReferences($connection))->has($schema, static::TABLE)) {
                    throw new RuntimeException('Incoming ' . static::LOWER_LABEL . ' legacy identity FK requires a separate explicit migration.');
                }
                foreach ($table->getForeignKeys() as $foreign) {
                    if (in_array('items_id', $foreign->getLocalColumns(), true)) {
                        throw new RuntimeException('Outgoing ' . static::LOWER_LABEL . ' legacy identity FK requires a separate explicit migration.');
                    }
                }
                $catalog = NativeCheckCatalog::snapshot($connection, static::TABLE);
                foreach ($catalog['checks'][static::TABLE] ?? [] as $check) {
                    $columns = $check['checked_columns'] ?? null;
                    $columns = is_string($columns) ? json_decode($columns, true, flags: JSON_THROW_ON_ERROR) : $columns;
                    if ((is_array($columns) && in_array('items_id', $columns, true))
                        || ($platform instanceof AbstractMySQLPlatform && preg_match('/\bitems_id\b/i', $check['clause'] ?? ''))) {
                        throw new RuntimeException('A custom CHECK on the stored ' . static::LOWER_LABEL . ' identity requires an explicit migration.');
                    }
                }
                if (!$platform instanceof AbstractMySQLPlatform && $connection->fetchOne(
                    "SELECT COUNT(*) FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_attribute a ON a.attrelid=d.refobjid AND a.attnum=d.refobjsubid JOIN pg_catalog.pg_attrdef f ON f.oid=d.objid "
                    . "WHERE a.attrelid=to_regclass(?) AND a.attname='items_id' AND d.classid='pg_catalog.pg_attrdef'::regclass AND NOT (f.adrelid=a.attrelid AND f.adnum=a.attnum)",
                    [static::TABLE]
                ) > 0) {
                    throw new RuntimeException('A dependent generated/default expression on the stored ' . static::LOWER_LABEL . ' identity requires an explicit migration.');
                }
                $indexes = [];
                foreach ($manager->listTableIndexes(static::TABLE) as $index) {
                    if (in_array('items_id', $index->getUnquotedColumns(), true)) {
                        $indexes[] = ['name' => $index->getName(), 'columns' => $index->getUnquotedColumns(),
                            'unique' => $index->isUnique(), 'flags' => $index->getFlags(), 'options' => $index->getOptions()];
                    }
                }
                $state = ['complete' => false, 'phase' => 'audited', 'source_hash' => $this->identityHash($connection, 'items_id'),
                    'items_comment' => $table->getColumn('items_id')->getComment(), 'indexes' => $indexes];
                Ledger::save($connection, static::PHASE, $state);
            }
            $this->assertSource($connection, $state);
            foreach ($this->columns() as $column => $default) {
                $before = $manager->introspectTable(static::TABLE);
                if ($before->hasColumn($column)) {
                    $actual = $before->getColumn($column);
                    if (Type::lookupName($actual->getType()) !== 'bigint' || $actual->getNotnull()
                        || ($actual->getDefault() === null) !== ($default === null)
                        || ($default !== null && (string)$actual->getDefault() !== (string)$default)) {
                        throw new RuntimeException('Conflicting ' . static::LOWER_LABEL . ' adopted column: ' . $column);
                    }
                    continue;
                }
                $after = clone $before;
                $after->addColumn($column, 'bigint', ['notnull' => false, 'default' => $default]);
                foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
                    $connection->executeStatement($sql);
                    $progress && $progress('columns', $sql);
                }
            }
            if (!$this->generated($connection) && $manager->introspectTable(static::TABLE)->hasColumn('items_id')) {
                $this->assertSource($connection, $state);
                $kind = $platform instanceof AbstractMySQLPlatform ? 'CAST(itemtype AS BINARY)' : 'itemtype';
                $set = $selected = [];
                foreach (static::OWNERS as $literal => [$column]) {
                    $test = $kind . ' IN (' . $platform->quoteStringLiteral($literal) . ')';
                    $set[] = $platform->quoteIdentifier($column) . ' = CASE WHEN ' . $test . ' AND items_id > 0 THEN items_id ELSE NULL END';
                    $selected[] = $test;
                }
                $set[] = 'opaque_parent_id = CASE WHEN ' . implode(' OR ', $selected) . ' THEN NULL ELSE items_id END';
                $sql = 'UPDATE ' . static::TABLE . ' SET ' . implode(', ', $set);
                $connection->executeStatement($sql);
                $projection = static::policy($platform)['projection'];
                if ($this->identityHash($connection, '(' . $projection . ')') !== $state['source_hash']) {
                    throw new RuntimeException(static::LABEL . ' copied ownership does not preserve the source identity.');
                }
                $state['phase'] = 'copied';
                Ledger::save($connection, static::PHASE, $state);
                $progress && $progress('copy', $sql);
            }
            $this->assertSource($connection, $state);
            $this->constraints($connection, $progress);
            if (!$this->generated($connection)) {
                $before = $manager->introspectTable(static::TABLE);
                $comment = $state['items_comment'] ?? '';
                $declaration = 'BIGINT GENERATED ALWAYS AS (' . static::policy($platform)['projection'] . ') STORED';
                if ($platform->supportsInlineColumnComments() && $comment !== '') {
                    $declaration .= ' ' . $platform->getInlineColumnCommentSQL($comment);
                }
                if ($platform instanceof AbstractMySQLPlatform) {
                    $sql = 'ALTER TABLE ' . static::TABLE . ' MODIFY COLUMN items_id ' . $declaration;
                    $connection->executeStatement($sql);
                    $this->verify($connection);
                    $state['phase'] = 'projected';
                    Ledger::save($connection, static::PHASE, $state);
                    $progress && $progress('projection', $sql);
                } else {
                    // The checkpoint retains every original identity/index before
                    // the first destructive statement; a retry can resume absent items_id.
                    foreach ($state['indexes'] as $index) {
                        if ($manager->introspectTable(static::TABLE)->hasIndex($index['name'])) {
                            $sql = $platform->getDropIndexSQL($index['name'], static::TABLE);
                            $connection->executeStatement($sql);
                            $progress && $progress('projection', $sql);
                        }
                    }
                    if ($before->hasColumn('items_id')) {
                        $sql = 'ALTER TABLE ' . static::TABLE . ' DROP COLUMN items_id';
                        $connection->executeStatement($sql);
                        $progress && $progress('projection', $sql);
                    }
                    $sql = 'ALTER TABLE ' . static::TABLE . ' ADD COLUMN items_id ' . $declaration;
                    $connection->executeStatement($sql);
                    if ($comment !== '') {
                        $connection->executeStatement($platform->getCommentOnColumnSQL(static::TABLE, 'items_id', $comment));
                    }
                    $this->verify($connection, indexes: false);
                    $state['phase'] = 'projected';
                    Ledger::save($connection, static::PHASE, $state);
                    $progress && $progress('projection', $sql);
                }
            }
            $before = $manager->introspectTable(static::TABLE);
            $after = clone $before;
            foreach ($state['indexes'] as $index) {
                if ($after->hasIndex($index['name'])) {
                    if ($after->getIndex($index['name'])->getUnquotedColumns() !== $index['columns']) {
                        throw new RuntimeException('Conflicting retained ' . static::LOWER_LABEL . ' index: ' . $index['name']);
                    }
                    continue;
                }
                if ($index['unique']) {
                    $after->addUniqueIndex($index['columns'], $index['name'], $index['options']);
                } else {
                    $after->addIndex($index['columns'], $index['name'], $index['flags'], $index['options']);
                }
            }
            foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
                $connection->executeStatement($sql);
                $progress && $progress('indexes', $sql);
            }
            $this->verify($connection);
            $state['phase'] = 'verified';
            $state['complete'] = true;
            Ledger::save($connection, static::PHASE, $state);
        };
        if ($mysql) {
            $apply();
        } else {
            $connection->transactional($apply);
        }
    }

    private function constraints(Connection $connection, ?callable $progress): void
    {
        $platform = $connection->getDatabasePlatform();
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable(static::TABLE);
        $after = clone $before;
        foreach (static::OWNERS as [$owner, $target, $index, $foreign]) {
            if (!$after->hasIndex($index)) {
                $after->addIndex([$owner], $index);
            } elseif ($after->getIndex($index)->getUnquotedColumns() !== [$owner]) {
                throw new RuntimeException('Conflicting ' . static::LOWER_LABEL . ' owning lookup index.');
            }
            if (!$after->hasForeignKey($foreign)) {
                $after->addForeignKeyConstraint($target, [$owner], ['id'], ['onDelete' => 'RESTRICT'], $foreign);
            } else {
                $this->verifyForeign($connection, $after->getForeignKey($foreign), $owner, $target);
            }
        }
        foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
            $connection->executeStatement($sql);
            $progress && $progress('constraints', $sql);
        }
        $actual = $manager->introspectTable(static::TABLE);
        foreach (static::OWNERS as [$owner, $target, , $foreign]) {
            $this->verifyForeign($connection, $actual->getForeignKey($foreign), $owner, $target);
        }
        $catalog = NativeCheckCatalog::snapshot($connection, static::TABLE);
        $check = $catalog['checks'][static::TABLE][static::CHECK] ?? null;
        if ($check === null) {
            $sql = 'ALTER TABLE ' . static::TABLE . ' ADD CONSTRAINT ' . static::CHECK . ' CHECK (' . static::policy($platform)['check'] . ')';
            $connection->executeStatement($sql);
            $progress && $progress('constraints', $sql);
        }
        $differences = NativeSubjectSchema::differences($connection, [static::TABLE => ['items_id' => static::policy($platform)]], checksOnly: true);
        if ($differences) {
            throw new RuntimeException(implode("\n", $differences));
        }
        // Existing named CHECKs are never dropped/replaced to conceal drift;
        // complete native proof follows with the frozen generated projection.
    }

    private function generated(Connection $connection): bool
    {
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return MySQLGeneratedColumnInspection::isGenerated($connection, $connection->getDatabase(), static::TABLE, 'items_id');
        }
        return $connection->fetchOne("SELECT attgenerated FROM pg_catalog.pg_attribute WHERE attrelid=to_regclass(?) AND attname='items_id' AND NOT attisdropped", [static::TABLE]) === 's';
    }

    private function audit(Connection $connection, string $identity): void
    {
        $kind = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'CAST(n.itemtype AS BINARY)' : 'n.itemtype';
        if ($identity === 'items_id') {
            $identity = 'n.items_id';
        } else {
            foreach (['itemtype', ...array_keys($this->columns())] as $field) {
                $identity = str_replace($connection->getDatabasePlatform()->quoteIdentifier($field), 'n.' . $connection->getDatabasePlatform()->quoteIdentifier($field), $identity);
            }
        }
        foreach (static::OWNERS as $literal => [, $target]) {
            $invalid = (static::DISCRIMINATOR_NULLABLE ? '' : 'n.itemtype IS NULL OR ')
                . $identity . ' IS NULL OR (' . $kind . ' IN (' . $connection->getDatabasePlatform()->quoteStringLiteral($literal)
                . ') AND (' . $identity . ' < 0 OR (' . $identity . ' > 0 AND p.id IS NULL)))';
            $rows = $connection->fetchAllAssociative('SELECT n.id, n.itemtype, ' . $identity . ' AS items_id FROM ' . static::TABLE
                . ' n LEFT JOIN ' . $target . ' p ON p.id = ' . $identity . ' WHERE ' . $invalid . ' ORDER BY n.id LIMIT 5');
            if ($rows) {
                throw new RuntimeException('Invalid canonical ' . $literal . ' ' . static::PARENT_LABEL . '; resolve negative/orphan/null source identities explicitly; no rows were repaired. Samples: ' . json_encode($rows, JSON_THROW_ON_ERROR));
            }
        }
    }

    private function columns(): array
    {
        return array_fill_keys(array_column(static::OWNERS, 0), null) + ['opaque_parent_id' => 0];
    }

    private function assertSource(Connection $connection, array $state): void
    {
        if (!is_string($state['source_hash'] ?? null) || !is_array($state['indexes'] ?? null)) {
            throw new RuntimeException('Invalid ' . static::LOWER_LABEL . ' adopted checkpoint.');
        }
        $table = $connection->createSchemaManager()->introspectTable(static::TABLE);
        $identity = $table->hasColumn('items_id') ? 'items_id' : '(' . static::policy($connection->getDatabasePlatform())['projection'] . ')';
        if (!$table->hasColumn('items_id') && ($state['phase'] ?? null) !== 'copied') {
            throw new RuntimeException('Missing ' . static::LOWER_LABEL . ' source identity without a copied ownership checkpoint.');
        }
        if (($state['complete'] ?? false) !== true && $this->identityHash($connection, $identity) !== $state['source_hash']) {
            throw new RuntimeException(static::LABEL . ' identities changed after the owned adoption audit. Stop writers and reconcile the original checkpoint; no data was repaired.');
        }
        $this->audit($connection, $identity);
    }

    private function identityHash(Connection $connection, string $identity): string
    {
        $hash = hash_init('sha256');
        foreach ($connection->executeQuery('SELECT id, itemtype, ' . $identity . ' AS identity FROM ' . static::TABLE . ' ORDER BY id')->iterateAssociative() as $row) {
            hash_update($hash, json_encode([(string)$row['id'], $row['itemtype'], $row['identity'] === null ? null : (string)$row['identity']], JSON_THROW_ON_ERROR) . "\n");
        }
        return hash_final($hash);
    }

    private function verifyForeign(Connection $connection, ForeignKeyConstraint $foreign, string $owner, string $target): void
    {
        if ($foreign->getLocalColumns() !== [$owner] || $foreign->getForeignTableName() !== $target
            || $foreign->getForeignColumns() !== ['id'] || !in_array($foreign->onDelete(), [null, 'RESTRICT', 'NO ACTION'], true)
            || !in_array($foreign->onUpdate(), [null, 'RESTRICT', 'NO ACTION'], true)) {
            throw new RuntimeException('Conflicting native ' . static::LOWER_LABEL . ' owning FK.');
        }
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $native = $connection->fetchAssociative(
                'SELECT DELETE_RULE AS delete_rule, UPDATE_RULE AS update_rule, '
                . 'UNIQUE_CONSTRAINT_SCHEMA AS target_schema, REFERENCED_TABLE_NAME AS foreign_table, DATABASE() AS current_schema '
                . 'FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?',
                [static::TABLE, $foreign->getName()]
            );
            $valid = (int)$connection->fetchOne('SELECT @@SESSION.foreign_key_checks') === 1
                && is_array($native) && $native['target_schema'] === $native['current_schema'] && $native['foreign_table'] === $target
                && in_array($native['delete_rule'], ['RESTRICT', 'NO ACTION'], true)
                && in_array($native['update_rule'], ['RESTRICT', 'NO ACTION'], true);
        } else {
            $native = $connection->fetchAssociative(
                'SELECT c.convalidated, c.condeferrable, c.condeferred, c.confdeltype, c.confupdtype, '
                . "COALESCE(to_jsonb(c)->>'conenforced', 'true') AS enforced, c.confrelid=to_regclass(?) AS visible_target "
                . 'FROM pg_catalog.pg_constraint c WHERE c.conrelid=to_regclass(?) AND c.conname=? AND c.contype=?',
                [$target, static::TABLE, $foreign->getName(), 'f']
            );
            $valid = is_array($native) && in_array($native['convalidated'], [true, 1, '1', 't'], true)
                && in_array($native['visible_target'], [true, 1, '1', 't'], true)
                && in_array($native['enforced'], [true, 1, '1', 't', 'true'], true)
                && in_array($native['condeferrable'], [false, 0, '0', 'f'], true)
                && in_array($native['condeferred'], [false, 0, '0', 'f'], true)
                && in_array($native['confdeltype'], ['r', 'a'], true) && in_array($native['confupdtype'], ['r', 'a'], true);
        }
        if (!$valid) {
            throw new RuntimeException(static::LABEL . ' owning FK is missing, changed, unvalidated, deferred or unenforced.');
        }
    }

    /** Identifier/storage shape is part of the frozen adoption, not only its expression. */
    private function verifyColumns(Connection $connection, bool $legacy = false): void
    {
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            && (int)$connection->fetchOne('SELECT @@SESSION.foreign_key_checks') !== 1) {
            throw new RuntimeException(static::LABEL . ' adoption requires enabled native foreign key checks.');
        }
        $manager = $connection->createSchemaManager();
        $table = $manager->introspectTable(static::TABLE);
        $kind = $table->getColumn('itemtype');
        if (Type::lookupName($kind->getType()) !== 'string'
            || $kind->getNotnull() !== (!static::DISCRIMINATOR_NULLABLE)
            || $kind->getLength() !== static::DISCRIMINATOR_LENGTH) {
            $nullability = static::DISCRIMINATOR_NULLABLE ? 'nullable' : 'nonnull';
            throw new RuntimeException(sprintf(
                '%s source discriminator must be %s varchar(%d).',
                static::LABEL,
                $nullability,
                static::DISCRIMINATOR_LENGTH
            ));
        }
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $deterministic = $connection->fetchOne(
                'SELECT c.collisdeterministic FROM pg_catalog.pg_attribute a '
                . 'JOIN pg_catalog.pg_collation c ON c.oid=a.attcollation WHERE a.attrelid=to_regclass(?) AND a.attname=? AND NOT a.attisdropped',
                [static::TABLE, 'itemtype']
            );
            if (!in_array($deterministic, [true, 1, '1', 't'], true)) {
                throw new RuntimeException(static::LABEL . ' exact adoption requires its existing deterministic discriminator collation; no source spelling/collation was rewritten.');
            }
        }
        foreach (static::OWNERS as [, $targetTable]) {
            $target = $manager->introspectTable($targetTable)->getColumn('id');
            if (Type::lookupName($target->getType()) !== 'bigint' || !$target->getNotnull() || $target->getUnsigned()) {
                throw new RuntimeException('Parent target ' . $targetTable . ' identity must be nonnull BIGINT.');
            }
        }
        if ($legacy || ($table->hasColumn('items_id') && !$this->generated($connection))) {
            $identity = $table->getColumn('items_id');
            if (Type::lookupName($identity->getType()) !== 'bigint' || !$identity->getNotnull() || $identity->getUnsigned() || $identity->getAutoincrement() || (string)$identity->getDefault() !== '0') {
                throw new RuntimeException(static::LABEL . ' stored source identity must be nonnull BIGINT with legacy zero default.');
            }
            return;
        }
        foreach ($this->columns() as $name => $default) {
            if (!$table->hasColumn($name)) {
                // A legitimate journal can still precede this column's owned DDL.
                if ((Ledger::state($connection, static::PHASE)['complete'] ?? false) === true) {
                    throw new RuntimeException('Missing ' . static::LOWER_LABEL . ' adopted column: ' . $name);
                }
                continue;
            }
            $column = $table->getColumn($name);
            if (Type::lookupName($column->getType()) !== 'bigint' || $column->getNotnull() || $column->getUnsigned() || $column->getAutoincrement()
                || ($column->getDefault() === null) !== ($default === null)
                || ($default !== null && (string)$column->getDefault() !== (string)$default)) {
                throw new RuntimeException('Conflicting ' . static::LOWER_LABEL . ' adopted column: ' . $name);
            }
        }
        if ($table->hasColumn('items_id') && $this->generated($connection)) {
            $identity = $table->getColumn('items_id');
            if (Type::lookupName($identity->getType()) !== 'bigint' || $identity->getNotnull() || $identity->getUnsigned() || $identity->getAutoincrement() || $identity->getDefault() !== null) {
                throw new RuntimeException(static::LABEL . ' projected identity must be nullable BIGINT without a default.');
            }
        }
    }

    public function verify(Connection $connection, bool $indexes = true): void
    {
        $this->verifyColumns($connection);
        $this->assertSource($connection, Ledger::state($connection, static::PHASE) ?? []);
        $table = $connection->createSchemaManager()->introspectTable(static::TABLE);
        foreach (static::OWNERS as [$owner, $target, $index, $foreign]) {
            if (!$table->hasForeignKey($foreign)) {
                throw new RuntimeException('Missing native ' . static::LOWER_LABEL . ' owning FK.');
            }
            $this->verifyForeign($connection, $table->getForeignKey($foreign), $owner, $target);
            if (!$table->hasIndex($index) || $table->getIndex($index)->getUnquotedColumns() !== [$owner]) {
                throw new RuntimeException('Missing native ' . static::LOWER_LABEL . ' owning lookup index.');
            }
        }
        $differences = NativeSubjectSchema::differences($connection, [static::TABLE => ['items_id' => static::policy($connection->getDatabasePlatform())]]);
        if ($differences) {
            throw new RuntimeException(implode("\n", $differences));
        }
        if ($indexes) {
            foreach (Ledger::state($connection, static::PHASE)['indexes'] as $index) {
                if (!$table->hasIndex($index['name']) || $table->getIndex($index['name'])->getUnquotedColumns() !== $index['columns']
                    || $table->getIndex($index['name'])->isUnique() !== $index['unique']) {
                    throw new RuntimeException('Missing or changed retained ' . static::LOWER_LABEL . ' index: ' . $index['name']);
                }
            }
        }
    }
}
