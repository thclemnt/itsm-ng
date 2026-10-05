<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Table;

/** Shared frozen 2.2.0 typed-reference DDL; subclasses own subject and stock policy. */
abstract class TypedItemMigration
{
    abstract protected function tables(): array;

    abstract protected static function targets(): array;

    /** Container relationships may already use the target table's ordinary column. */
    protected static function column(string $target): string
    {
        return $target . '_id';
    }

    protected static function allowsEmptyReference(): bool
    {
        return false;
    }

    /** Frozen subclasses explicitly declare any real target with identifier zero. */
    protected static function minimumId(string $kind): int
    {
        return 1;
    }

    /** A subclass may impose domain invariants on stock with no selected target. */
    protected static function emptyReferenceSql(string $alias = '', ?AbstractPlatform $platform = null): string
    {
        return '(' . $alias . 'itemtype IS NULL OR ' . static::discriminatorSql($platform, $alias) . " = '')";
    }

    protected static function constraintName(string $table): string
    {
        return $table . '_typed_item_kind';
    }

    /** Appended frozen migrations can expand a formerly complete subject set. */
    protected function expandsTargets(): bool
    {
        return false;
    }

    protected function rebuildsProjection(Connection $connection): bool
    {
        return false;
    }

    /** Frozen subclasses may make their discriminator exact for a native platform. */
    protected static function discriminatorSql(?AbstractPlatform $platform = null, string $alias = ''): string
    {
        return $alias . 'itemtype';
    }

    protected static function identity(string $alias = '', ?AbstractPlatform $platform = null): string
    {
        $cases = [];
        foreach (static::targets() as $kind => $target) {
            $cases[] = "WHEN '" . $kind . "' THEN " . $alias . static::column($target);
        }
        $identity = 'CASE ' . static::discriminatorSql($platform, $alias) . ' ' . implode(' ', $cases) . ' ELSE NULL END';
        return static::allowsEmptyReference() ? 'COALESCE(' . $identity . ', 0)' : $identity;
    }

    private static function keyDeclaration(?AbstractPlatform $platform = null): string
    {
        return 'BIGINT GENERATED ALWAYS AS (' . static::identity('', $platform) . ') STORED';
    }

    private static function configureColumns(Table $table): void
    {
        foreach (static::targets() as $target) {
            $column = static::column($target);
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, 'bigint', ['notnull' => false]);
            }
            $index = $table->getName() . '_' . $column;
            if (!$table->hasIndex($index)) {
                $table->addIndex([$column], $index);
            }
        }
    }

    public static function configureTable(Table $table, ?AbstractPlatform $platform = null): void
    {
        self::configureColumns($table);
        $table->getColumn('itemtype')->setNotnull(!static::allowsEmptyReference())->setDefault(null);
        $table->getColumn('items_id')->setNotnull(false)->setDefault(null)->setColumnDefinition(self::keyDeclaration($platform));
    }

    private static function validReferenceSql(?array $targets = null, ?AbstractPlatform $platform = null): string
    {
        $targets ??= static::targets();
        $branches = [];
        foreach ($targets as $kind => $target) {
            $branch = [static::discriminatorSql($platform) . " = '" . $kind . "'", static::column($target) . ' IS NOT NULL', static::column($target) . ' >= ' . static::minimumId($kind)];
            foreach ($targets as $other) {
                if ($other !== $target) {
                    $branch[] = static::column($other) . ' IS NULL';
                }
            }
            $branches[] = '(' . implode(' AND ', $branch) . ')';
        }
        $selected = 'itemtype IS NOT NULL AND (' . implode(' OR ', $branches) . ')';
        if (!static::allowsEmptyReference()) {
            return $selected;
        }
        $empty = ['itemtype IS NULL', static::emptyReferenceSql('', $platform)];
        foreach ($targets as $target) {
            $empty[] = static::column($target) . ' IS NULL';
        }
        return '((' . implode(' AND ', $empty) . ') OR (' . $selected . '))';
    }

    public static function checkSql(string $table, ?AbstractPlatform $platform = null): string
    {
        if (!in_array($table, (new static())->tables(), true)) {
            throw new \InvalidArgumentException('Unsupported frozen typed item reference table');
        }
        return 'ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . static::constraintName($table) . ' CHECK (' . self::validReferenceSql(null, $platform) . ')';
    }

    public function plan(Connection $connection, ?IncomingProjectionReferences $incomingReferences = null): array
    {
        return $this->planInspectedTable($connection, null, $incomingReferences);
    }

    /** Reuse only the table captured by this call's caller, with frozen target ownership. */
    protected function planInspectedTable(Connection $connection, ?Table $inspection, ?IncomingProjectionReferences $incomingReferences = null): array
    {
        if ($inspection !== null && !in_array($inspection->getName(), $this->tables(), true)) {
            throw new \InvalidArgumentException('Typed item inspection belongs to a different table.');
        }
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $postgres = $platform instanceof PostgreSQLPlatform;
        $schema = $connection->fetchOne($postgres ? 'SELECT current_schema()' : 'SELECT DATABASE()');
        $plans = [];
        foreach ($this->tables() as $table) {
            $before = $inspection !== null && $inspection->getName() === $table ? clone $inspection : $manager->introspectTable($table);
            // DBAL synthesizes supporting indexes for FKs during introspection.
            // PostgreSQL does not create them, so they cannot be renamed as real indexes.
            $physicalIndexes = array_map(static fn ($index) => strtolower($index->getName()), $manager->listTableIndexes($table));
            foreach ($before->getIndexes() as $index) {
                if (array_intersect($index->getColumns(), array_map(static fn ($target) => static::column($target), static::targets()))
                    && !in_array(strtolower($index->getName()), $physicalIndexes, true)) {
                    $before->dropIndex($index->getName());
                }
            }
            $hasKey = $before->hasColumn('items_id');
            if (!$hasKey && array_filter(static::targets(), static fn ($target) => !$before->hasColumn(static::column($target)))) {
                throw new \RuntimeException('Missing typed item identity without recoverable canonical columns: ' . $table);
            }
            $generated = $hasKey && ($postgres
                ? (bool)$connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = 'items_id' AND is_generated = 'ALWAYS'", [$schema, $table])
                : \itsmng\Database\MySQLGeneratedColumnInspection::isGenerated($connection, $schema, $table, 'items_id'));
            $identity = $hasKey ? 'r.items_id' : '(' . static::identity('r.', $platform) . ')';
            $joins = $invalid = [];
            foreach (static::targets() as $kind => $target) {
                $alias = 'subject_' . count($joins);
                $joins[] = ' LEFT JOIN glpi_' . $target . ' ' . $alias . ' ON ' . $alias . '.id = ' . $identity;
                $invalid[] = "(r.itemtype = '" . $kind . "' AND (" . $alias . '.id IS NULL OR ' . $identity . ' < ' . static::minimumId($kind) . '))';
            }
            $invalidSql = "r.itemtype IS NULL OR r.itemtype NOT IN ('" . implode("', '", array_keys(static::targets())) . "') OR " . $identity . ' IS NULL OR ' . implode(' OR ', $invalid);
            if (static::allowsEmptyReference()) {
                $invalidSql = 'NOT (' . static::emptyReferenceSql('r.', $platform) . ' AND (' . $identity . ' IS NULL OR ' . $identity . ' = 0)) AND (' . $invalidSql . ')';
            }
            $count = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' r' . implode('', $joins) . ' WHERE ' . $invalidSql);
            if ($count) {
                $samples = $connection->fetchAllAssociative('SELECT r.id, r.itemtype, ' . $identity . ' AS items_id FROM ' . $table . ' r' . implode('', $joins) . ' WHERE ' . $invalidSql . ' LIMIT 5');
                throw new \RuntimeException('Invalid or unsupported legacy typed item references: ' . $table . ' (' . $count . '); samples: ' . json_encode($samples, JSON_THROW_ON_ERROR));
            }
            foreach (static::targets() as $kind => $target) {
                $column = static::column($target);
                if ($before->hasColumn($column) && $connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' r WHERE r.' . $column
                    . " IS NOT NULL AND (r.itemtype IS NULL OR r.itemtype <> '" . $kind . "' OR r." . $column . ' <> ' . $identity . ')')) {
                    throw new \RuntimeException('Canonical and legacy typed item references disagree: ' . $table . '.' . $column);
                }
            }
            $canonicalTargets = $this->expandsTargets()
                ? array_filter(static::targets(), static fn ($target) => $before->hasColumn(static::column($target)))
                : null;
            if (($generated || !$hasKey) && $connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE NOT (' . self::validReferenceSql($canonicalTargets, $platform) . ')')) {
                throw new \RuntimeException('Invalid canonical typed item references: ' . $table);
            }
            $after = clone $before;
            self::configureColumns($after);
            $after->getColumn('itemtype')->setNotnull(!static::allowsEmptyReference())->setDefault(null);
            $sql = $platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after));
            $keySql = [];
            if (!$generated || $this->rebuildsProjection($connection)) {
                $incomingReferences ??= new IncomingProjectionReferences($connection);
                if ($incomingReferences->has($schema, $table)) {
                    throw new \RuntimeException('Incoming typed legacy item foreign key requires an explicit migration');
                }
                foreach ($before->getForeignKeys() as $foreign) {
                    if (in_array('items_id', $foreign->getLocalColumns(), true)) {
                        throw new \RuntimeException('Custom typed legacy item foreign key requires an explicit migration');
                    }
                }
                $without = clone $after;
                $indexes = [];
                foreach ($without->getIndexes() as $index) {
                    if (in_array('items_id', array_map(static fn ($column) => trim($column, '`"'), $index->getColumns()), true)) {
                        $indexes[] = $index;
                        $without->dropIndex($index->getName());
                    }
                }
                if ($hasKey) {
                    $without->dropColumn('items_id');
                }
                $withKey = clone $without;
                $comment = $hasKey ? $before->getColumn('items_id')->getComment() : '';
                $declaration = self::keyDeclaration($platform);
                if ($platform->supportsInlineColumnComments() && $comment !== '') {
                    $declaration .= ' ' . $platform->getInlineColumnCommentSQL($comment);
                }
                $withKey->addColumn('items_id', 'bigint', ['notnull' => false, 'columnDefinition' => $declaration, 'comment' => $comment]);
                foreach ($indexes as $index) {
                    if ($index->isUnique()) {
                        $withKey->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
                    } else {
                        $withKey->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
                    }
                }
                $keySql = $postgres
                    ? array_merge(
                        $platform->getAlterTableSQL($manager->createComparator()->compareTables($after, $without)),
                        $platform->getAlterTableSQL($manager->createComparator()->compareTables($without, $withKey))
                    )
                    : $platform->getAlterTableSQL($manager->createComparator()->compareTables($after, $withKey));
                if (!$postgres && $hasKey && ($keySql === [] || ($generated && $this->expandsTargets() && $this->rebuildsProjection($connection)))) {
                    // DBAL excludes generation expressions from column comparison.
                    // The native catalogue above determines whether to install or
                    // rebuild this frozen projection even when its BIGINT shape
                    // compares equal, including ordinary nullable legacy columns.
                    $keySql = ['ALTER TABLE ' . $platform->quoteIdentifier($table)
                        . ' MODIFY COLUMN ' . $platform->quoteIdentifier('items_id') . ' ' . $declaration];
                }
            }
            $checked = $connection->fetchOne('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ?', [$schema, $table, static::constraintName($table), 'CHECK']);
            $constraints = $checked ? [] : [self::checkSql($table, $platform)];
            foreach (static::targets() as $target) {
                $column = static::column($target);
                $name = 'fk_' . substr($table, 5) . '_' . $column;
                $foreign = new ForeignKeyConstraint([$column], 'glpi_' . $target, ['id'], $name, ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT']);
                if (!$before->hasForeignKey($name)) {
                    $constraints[] = $platform->getCreateForeignKeySQL($foreign, $table);
                } else {
                    $existing = $before->getForeignKey($name);
                    if ($existing->getLocalColumns() !== [$column] || $existing->getForeignTableName() !== 'glpi_' . $target
                        || $existing->getForeignColumns() !== ['id'] || !in_array($existing->onDelete(), [null, 'RESTRICT', 'NO ACTION'], true)
                        || !in_array($existing->onUpdate(), [null, 'RESTRICT', 'NO ACTION'], true)) {
                        throw new \RuntimeException('Existing typed item reference FK has a different definition: ' . $name);
                    }
                }
            }
            $plans[$table] = ['sql' => $sql, 'key_sql' => $keySql, 'constraint_sql' => $constraints, 'copy_legacy' => $hasKey && !$generated];
        }
        return $plans;
    }

    public function apply(Connection $connection): array
    {
        $plan = $this->plan($connection);
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (!$postgres && $connection->isTransactionActive() && array_filter($plan, static fn ($entry) => $entry['sql'] || $entry['key_sql'] || $entry['constraint_sql'])) {
            throw new \RuntimeException('MySQL typed item reference DDL must run outside an application transaction');
        }
        $apply = static function () use ($connection, $plan): array {
            foreach ($plan as $table => $entry) {
                foreach ($entry['sql'] as $sql) {
                    $connection->executeStatement($sql);
                }
                if ($entry['copy_legacy']) {
                    if (static::allowsEmptyReference()) {
                        $connection->executeStatement('UPDATE ' . $table . ' SET itemtype = NULL WHERE ' . static::discriminatorSql($connection->getDatabasePlatform()) . " = ''");
                    }
                    $assignments = [];
                    foreach (static::targets() as $kind => $target) {
                        $assignments[] = static::column($target) . " = CASE WHEN itemtype = '" . $kind . "' THEN items_id ELSE NULL END";
                    }
                    $connection->executeStatement('UPDATE ' . $table . ' SET ' . implode(', ', $assignments));
                }
                foreach (array_merge($entry['key_sql'], $entry['constraint_sql']) as $sql) {
                    $connection->executeStatement($sql);
                }
            }
            return $plan;
        };
        return $postgres ? $connection->transactional($apply) : $apply();
    }
}
