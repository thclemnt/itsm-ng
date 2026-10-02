<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;

/** Widen in place, preserving data, sequences and all existing constraint definitions. */
final class WideIdentifiers
{
    public function __construct(private ?array $identifiers = null)
    {
    }

    /** The returned operations are journaled before MySQL's first implicit DDL commit. */
    public function plan(Connection $connection): array
    {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('The ORM schema requires 64-bit PHP integers.');
        }
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $postgres = $platform instanceof PostgreSQLPlatform;
        $quote = $platform->quoteIdentifier(...);
        $namespace = $connection->fetchOne($postgres ? 'SELECT current_schema()' : 'SELECT DATABASE()');
        $scope = $this->identifiers ?? IdentifierColumns::history()['identifiers'];
        $tables = $foreignKeys = [];
        foreach ($manager->listTableNames() as $name) {
            $tables[$name] = $manager->introspectTable($name);
            foreach ($tables[$name]->getForeignKeys() as $foreign) {
                $foreignKeys[] = [$name, $foreign];
            }
        }
        // Include actual FK edges from plugin/custom tables so their types stay compatible.
        do {
            $changed = false;
            foreach ($foreignKeys as [$table, $foreign]) {
                foreach ($foreign->getLocalColumns() as $i => $column) {
                    $target = $foreign->getForeignTableName();
                    $targetColumn = $foreign->getForeignColumns()[$i];
                    if (in_array($targetColumn, $scope[$target] ?? [], true) || in_array($column, $scope[$table] ?? [], true)) {
                        foreach ([[$table, $column], [$target, $targetColumn]] as [$name, $field]) {
                            if (!in_array($field, $scope[$name] ?? [], true)) {
                                $scope[$name][] = $field;
                                $changed = true;
                            }
                        }
                    }
                }
            }
        } while ($changed);
        $widen = [];
        foreach ($scope as $name => $columns) {
            if (!isset($tables[$name])) {
                continue; // The master creates new membership tables later.
            }
            foreach ($columns as $column) {
                if (!$tables[$name]->hasColumn($column)) {
                    continue;
                }
                $type = Type::lookupName($tables[$name]->getColumn($column)->getType());
                if (!in_array($type, ['smallint', 'integer', 'bigint'], true)) {
                    throw new \RuntimeException('Unexpected identifier type: ' . $name . '.' . $column . ' (' . $type . ')');
                }
                if ($type !== 'bigint') {
                    $widen[$name][] = $column;
                }
            }
        }
        $storage = [];
        if ($this->identifiers === null) {
            // Adopt frozen installer widths and preserve legacy Unicode/comment declarations.
            foreach (IdentifierColumns::history()['storage'] as $name => $definitions) {
                if (!isset($tables[$name])) {
                    continue;
                }
                foreach ($definitions as $field => $definition) {
                    if (!$tables[$name]->hasColumn($field)) {
                        continue;
                    }
                    $column = $tables[$name]->getColumn($field);
                    $after = clone $column;
                    self::configureStorage($after, $definition, $postgres);
                    if ($column->getType()->getSQLDeclaration($column->toArray(), $platform) !== $after->getType()->getSQLDeclaration($after->toArray(), $platform)
                        || $column->getComment() !== $after->getComment() || $column->getPlatformOptions() !== $after->getPlatformOptions()) {
                        $storage[$name][$field] = $definition;
                        $widen[$name] ??= [];
                    }
                }
            }
        }
        $generatedColumns = [];
        foreach ($widen as $name => $columns) {
            $generatedColumns[$name] = $columns ? $connection->fetchAllAssociative($postgres
                ? "SELECT column_name, generation_expression FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND is_generated = 'ALWAYS' ORDER BY ordinal_position"
                : "SELECT column_name, generation_expression FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND extra LIKE '%GENERATED%' ORDER BY ordinal_position", [$namespace, $name]) : [];
        }
        $dropForeign = $restoreForeign = $dropGenerated = $restoreGenerated = $alter = $dropChecks = $restoreChecks = [];
        $operation = static fn (string $sql, string $kind = 'sql', string $table = '', string $name = '') => compact('sql', 'kind', 'table', 'name');
        foreach ($foreignKeys as [$table, $foreign]) {
            $generatedNames = array_column($generatedColumns[$table] ?? [], 'column_name');
            $supportingGeneratedIndex = false;
            foreach ($tables[$table]->getIndexes() as $index) {
                if (array_intersect($index->getColumns(), $generatedNames)
                    && array_slice($index->getColumns(), 0, count($foreign->getLocalColumns())) === $foreign->getLocalColumns()) {
                    $supportingGeneratedIndex = true;
                }
            }
            if ($supportingGeneratedIndex || array_intersect($foreign->getForeignColumns(), array_column($generatedColumns[$foreign->getForeignTableName()] ?? [], 'column_name'))
                || array_intersect($foreign->getLocalColumns(), $widen[$table] ?? []) || array_intersect($foreign->getForeignColumns(), $widen[$foreign->getForeignTableName()] ?? [])) {
                $dropForeign[] = $operation($platform->getDropForeignKeySQL($foreign->getQuotedName($platform), $quote($table)), 'drop_fk', $table, $foreign->getName());
                $restoreForeign[] = $operation($platform->getCreateForeignKeySQL($foreign, $quote($table)), 'add_fk', $table, $foreign->getName());
            }
        }
        foreach ($widen as $name => $columns) {
            $before = clone $tables[$name];
            foreach ($before->getForeignKeys() as $key) {
                $before->removeForeignKey($key->getName());
            }
            // PostgreSQL cannot alter a source type while a stored generated column depends on it.
            $generated = $generatedColumns[$name];
            $generatedNames = array_column($generated, 'column_name');
            $indexes = [];
            foreach ($manager->listTableIndexes($name) as $index) {
                if (array_intersect($index->getColumns(), $generatedNames)) {
                    if ($index->isPrimary()) {
                        throw new \RuntimeException('Generated primary key requires explicit upgrade handling: ' . $name);
                    }
                    $indexes[] = $index;
                    $dropGenerated[] = $operation($platform->getDropIndexSQL($index->getQuotedName($platform), $quote($name)), 'drop_index', $name, $index->getName());
                }
            }
            foreach (array_reverse($generated) as $row) {
                $dropGenerated[] = $operation('ALTER TABLE ' . $quote($name) . ' DROP COLUMN ' . $quote($row['column_name']), 'drop_column', $name, $row['column_name']);
            }
            foreach ($generated as $row) {
                $column = $before->getColumn($row['column_name']);
                $data = $column->toArray();
                $type = in_array($column->getName(), $scope[$name] ?? [], true) ? 'BIGINT' : $column->getType()->getSQLDeclaration($data, $platform);
                // Generated identity columns are signed, including COALESCE keys for unsigned legacy IDs.
                $data['columnDefinition'] = trim($type) . ' GENERATED ALWAYS AS (' . $row['generation_expression'] . ') STORED';
                if ($column->getNotnull()) {
                    $data['columnDefinition'] .= ' NOT NULL';
                }
                if ($platform->supportsInlineColumnComments() && $column->getComment() !== '') {
                    $data['columnDefinition'] .= ' ' . $platform->getInlineColumnCommentSQL($column->getComment());
                }
                $restoreGenerated[] = $operation('ALTER TABLE ' . $quote($name) . ' ADD ' . $platform->getColumnDeclarationSQL($quote($column->getName()), $data), 'add_column', $name, $column->getName());
                if (!$platform->supportsInlineColumnComments() && $column->getComment() !== '') {
                    $restoreGenerated[] = $operation($platform->getCommentOnColumnSQL($quote($name), $quote($column->getName()), $column->getComment()));
                }
                foreach ($before->getIndexes() as $index) {
                    if (in_array($column->getName(), $index->getColumns(), true)) {
                        $before->dropIndex($index->getName());
                    }
                }
                $before->dropColumn($column->getName());
            }
            foreach ($indexes as $index) {
                $restoreGenerated[] = $operation($platform->getCreateIndexSQL($index, $quote($name)), 'add_index', $name, $index->getName());
            }
            if ($generated) {
                $checks = $connection->fetchAllAssociative($postgres
                    ? "SELECT c.conname AS name, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = ? AND t.relname = ? AND c.contype = 'c'"
                    : "SELECT t.constraint_name AS name, CONCAT('CHECK (', c.check_clause, ')') AS definition FROM information_schema.table_constraints t JOIN information_schema.check_constraints c ON c.constraint_schema = t.constraint_schema AND c.constraint_name = t.constraint_name"
                        . ($platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform ? ' AND c.table_name = t.table_name' : '')
                        . " WHERE t.constraint_schema = ? AND t.table_name = ? AND t.constraint_type = 'CHECK'", [$namespace, $name]);
                foreach ($checks as $check) {
                    $drop = $postgres || $platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform ? 'DROP CONSTRAINT ' : 'DROP CHECK ';
                    $dropChecks[] = $operation('ALTER TABLE ' . $quote($name) . ' ' . $drop . $quote($check['name']), 'drop_check', $name, $check['name']);
                    $restoreChecks[] = $operation('ALTER TABLE ' . $quote($name) . ' ADD CONSTRAINT ' . $quote($check['name']) . ' ' . $check['definition'], 'add_check', $name, $check['name']);
                }
            }
            $after = clone $before;
            foreach ($columns as $column) {
                if ($after->hasColumn($column)) {
                    $after->getColumn($column)->setType(Type::getType('bigint'));
                }
                if ($postgres && $tables[$name]->getColumn($column)->getAutoincrement()) {
                    $sequence = $connection->fetchOne('SELECT pg_get_serial_sequence(?, ?)', [$name, $column]);
                    if ($sequence !== null && $sequence !== false) {
                        $alter[] = $operation('ALTER SEQUENCE ' . $quote($sequence) . ' AS bigint');
                    }
                }
            }
            foreach ($storage[$name] ?? [] as $column => $definition) {
                self::configureStorage($after->getColumn($column), $definition, $postgres);
            }
            foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
                $alter[] = $operation($sql);
            }
        }
        return array_merge($dropForeign, $dropChecks, $dropGenerated, $alter, $restoreGenerated, $restoreChecks, $restoreForeign);
    }

    private static function configureStorage(\Doctrine\DBAL\Schema\Column $column, array $definition, bool $postgres): void
    {
        if (!$postgres) {
            $allowed = match ($definition['type']) {
                'smallint' => ['boolean', 'smallint'],
                'float' => ['smallfloat', 'float'],
                'bigint' => ['smallint', 'integer', 'bigint'],
                default => [$definition['type']],
            };
            if (!in_array(Type::lookupName($column->getType()), $allowed, true)) {
                throw new \RuntimeException('Unexpected legacy storage type for ' . $column->getName());
            }
            $column->setType(Type::getType($definition['type']))->setLength($definition['length'])
                ->setPrecision($definition['precision'])->setScale($definition['scale']);
            foreach ($definition['platformOptions'] ?? [] as $name => $value) {
                $column->setPlatformOption($name, $value);
            }
        }
        if (array_key_exists('comment', $definition)) {
            $column->setComment($definition['comment']);
        }
    }

    /** Existence checks make journal replay safe after a DDL commit but before its checkpoint. */
    public static function execute(Connection $connection, array $operation): void
    {
        if ($operation['kind'] !== 'sql') {
            [$action, $kind] = explode('_', $operation['kind'], 2);
            if ($action === 'drop' && $kind === 'check' && $connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform
                && $connection->fetchOne('SELECT level FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?', [$operation['table'], $operation['name']]) === 'Column') {
                // MariaDB manages inline checks with their column, including JSON aliases.
                // A dropped generated column loses its inline check automatically; the
                // restoration step reinstalls it if necessary. Other columns retain theirs.
                return;
            }
            $manager = $connection->createSchemaManager();
            $table = $manager->introspectTable($operation['table']);
            $exists = match ($kind) {
                'column' => $table->hasColumn($operation['name']),
                'fk' => $table->hasForeignKey($operation['name']),
                'index' => array_key_exists(strtolower($operation['name']), $manager->listTableIndexes($operation['table'])),
                'check' => (bool)$connection->fetchOne('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ?', [$connection->fetchOne($connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'SELECT current_schema()' : 'SELECT DATABASE()'), $operation['table'], $operation['name'], 'CHECK']),
            };
            if ($exists === ($action === 'add')) {
                return;
            }
        }
        $connection->executeStatement($operation['sql']);
    }
}
