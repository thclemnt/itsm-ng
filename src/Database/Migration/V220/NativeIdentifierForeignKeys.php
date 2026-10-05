<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Table;

/** Qualified actual FK graph for one read-only identifier planning operation. */
final class NativeIdentifierForeignKeys
{
    private readonly string $namespace;
    private array $constraints = [];

    public function __construct(private readonly Connection $connection)
    {
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $this->namespace = (string)$connection->fetchOne($postgres ? 'SELECT current_schema()' : 'SELECT DATABASE()');
        if ($this->namespace === '') {
            throw new \RuntimeException('Identifier adoption requires an actual configured namespace.');
        }
        if ($postgres) {
            $rows = $connection->fetchAllAssociative("SELECT ln.nspname AS local_schema, lt.relname AS local_table, f.conname AS constraint_name,
                fn.nspname AS target_schema, ft.relname AS target_table, columns.ordinality AS position,
                la.attname AS local_column, fa.attname AS target_column, f.convalidated, f.condeferrable, f.condeferred,
                f.confupdtype AS update_rule, f.confdeltype AS delete_rule, f.confmatchtype AS match_type, pg_get_constraintdef(f.oid) AS definition
                FROM pg_catalog.pg_constraint f
                JOIN pg_catalog.pg_class lt ON lt.oid=f.conrelid JOIN pg_catalog.pg_namespace ln ON ln.oid=lt.relnamespace
                JOIN pg_catalog.pg_class ft ON ft.oid=f.confrelid JOIN pg_catalog.pg_namespace fn ON fn.oid=ft.relnamespace
                CROSS JOIN LATERAL unnest(f.conkey,f.confkey) WITH ORDINALITY AS columns(local_number,target_number,ordinality)
                JOIN pg_catalog.pg_attribute la ON la.attrelid=lt.oid AND la.attnum=columns.local_number
                JOIN pg_catalog.pg_attribute fa ON fa.attrelid=ft.oid AND fa.attnum=columns.target_number
                WHERE f.contype='f' AND (ln.nspname=? OR fn.nspname=?)
                ORDER BY ln.nspname,lt.relname,f.conname,columns.ordinality", [$this->namespace, $this->namespace]);
        } else {
            // Portable MySQL FKs discard REFERENCED_TABLE_SCHEMA. Preserve it
            // here, including every visible external owner referencing this DB.
            $rows = $connection->fetchAllAssociative('SELECT CONSTRAINT_SCHEMA AS local_schema, TABLE_NAME AS local_table, CONSTRAINT_NAME AS constraint_name,
                REFERENCED_TABLE_SCHEMA AS target_schema, REFERENCED_TABLE_NAME AS target_table, ORDINAL_POSITION AS position,
                COLUMN_NAME AS local_column, REFERENCED_COLUMN_NAME AS target_column
                FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL
                AND (CONSTRAINT_SCHEMA=? OR REFERENCED_TABLE_SCHEMA=?)
                ORDER BY CONSTRAINT_SCHEMA,TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION', [$this->namespace, $this->namespace]);
            $actions = [];
            foreach ($connection->fetchAllAssociative('SELECT CONSTRAINT_SCHEMA AS local_schema, TABLE_NAME AS local_table, CONSTRAINT_NAME AS constraint_name,
                UNIQUE_CONSTRAINT_SCHEMA AS target_schema, REFERENCED_TABLE_NAME AS target_table, UPDATE_RULE AS update_rule, DELETE_RULE AS delete_rule, MATCH_OPTION AS match_type
                FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? OR UNIQUE_CONSTRAINT_SCHEMA=?', [$this->namespace, $this->namespace]) as $action) {
                $key = self::key($action);
                if (isset($actions[$key])) {
                    throw new \RuntimeException('Ambiguous native identifier foreign key actions: ' . $key);
                }
                $actions[$key] = $action;
            }
            foreach ($rows as &$row) {
                $action = $actions[self::key($row)] ?? null;
                if ($action === null || $action['target_schema'] !== $row['target_schema'] || $action['target_table'] !== $row['target_table']) {
                    throw new \RuntimeException('Native identifier foreign key target/actions disagree: ' . self::key($row));
                }
                $row += $action;
            }
            unset($row);
        }
        foreach ($rows as $row) {
            $key = self::key($row);
            $position = (int)$row['position'];
            if ($position !== count($this->constraints[$key]['columns'] ?? []) + 1) {
                throw new \RuntimeException('Incomplete native identifier foreign key ordinals: ' . $key);
            }
            $identity = array_diff_key($row, array_flip(['position', 'local_column', 'target_column']));
            if (isset($this->constraints[$key]) && $this->constraints[$key]['identity'] !== $identity) {
                throw new \RuntimeException('Inconsistent native identifier foreign key identity: ' . $key);
            }
            $this->constraints[$key]['identity'] = $identity;
            $this->constraints[$key]['columns'][] = [$row['local_column'], $row['target_column']];
        }
    }

    private static function key(array $identity): string
    {
        return json_encode([$identity['local_schema'], $identity['local_table'], $identity['constraint_name']], JSON_THROW_ON_ERROR);
    }

    /** Follow only actual edges entirely inside the supplied owned namespace. */
    public function expand(array $scope): array
    {
        do {
            $changed = false;
            foreach ($this->constraints as $constraint) {
                $identity = $constraint['identity'];
                if ($identity['local_schema'] !== $this->namespace || $identity['target_schema'] !== $this->namespace) {
                    continue;
                }
                foreach ($constraint['columns'] as [$local, $target]) {
                    $child = $identity['local_table'];
                    $parent = $identity['target_table'];
                    if (!in_array($local, $scope[$child] ?? [], true) && !in_array($target, $scope[$parent] ?? [], true)) {
                        continue;
                    }
                    foreach ([[$child, $local], [$parent, $target]] as [$table, $column]) {
                        if (!in_array($column, $scope[$table] ?? [], true)) {
                            $scope[$table][] = $column;
                            $changed = true;
                        }
                    }
                }
            }
        } while ($changed);
        return $scope;
    }

    /** Native qualification decides whether portable FK reconstruction is owned. */
    public function ownedTarget(Table $table, ForeignKeyConstraint $foreign): ?string
    {
        if (($table->getNamespaceName() ?? $this->namespace) !== $this->namespace) {
            return null;
        }
        $name = $table->getName();
        if ($table->getNamespaceName() === $this->namespace) {
            $name = substr($name, strlen($this->namespace) + 1);
        }
        $key = self::key(['local_schema' => $this->namespace, 'local_table' => $name, 'constraint_name' => $foreign->getName()]);
        $constraint = $this->constraints[$key] ?? null;
        if ($constraint === null || array_column($constraint['columns'], 0) !== $foreign->getUnquotedLocalColumns()
            || array_column($constraint['columns'], 1) !== $foreign->getUnquotedForeignColumns()) {
            throw new \RuntimeException('Native/portable identifier foreign key columns disagree: ' . $key);
        }
        return $constraint['identity']['target_schema'] === $this->namespace ? $constraint['identity']['target_table'] : null;
    }

    /** Preserve the actual owned PostgreSQL constraint's complete native semantics. */
    public function restorationSql(Table $table, ForeignKeyConstraint $foreign): string
    {
        if ($this->ownedTarget($table, $foreign) === null) {
            throw new \RuntimeException('External identifier foreign key cannot be reconstructed.');
        }
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof PostgreSQLPlatform) {
            return $platform->getCreateForeignKeySQL($foreign, $platform->quoteIdentifier($table->getName()));
        }
        $name = $table->getName();
        if ($table->getNamespaceName() === $this->namespace) {
            $name = substr($name, strlen($this->namespace) + 1);
        }
        $key = self::key(['local_schema' => $this->namespace, 'local_table' => $name, 'constraint_name' => $foreign->getName()]);
        $identity = $this->constraints[$key]['identity'];
        $quote = $platform->quoteSingleIdentifier(...);
        // pg_get_constraintdef retains MATCH, validation and deferrability;
        // DBAL's portable FK renderer cannot represent all of those flags.
        return 'ALTER TABLE ' . $quote($identity['local_schema']) . '.' . $quote($identity['local_table'])
            . ' ADD CONSTRAINT ' . $quote($identity['constraint_name']) . ' ' . $identity['definition'];
    }

    /** External edges may remain only when none of their owned columns change. */
    public function assertOwnedChanges(array $widen, array $generated, array $tables): void
    {
        foreach ($widen as $name => $_) {
            $table = $tables[$name] ?? null;
            if ($table !== null && ($table->getNamespaceName() ?? $this->namespace) !== $this->namespace) {
                throw new \RuntimeException('Identifier adoption cannot change a table outside its configured namespace: ' . $name);
            }
        }
        foreach ($this->constraints as $constraint) {
            $identity = $constraint['identity'];
            if ($identity['local_schema'] === $this->namespace && $identity['target_schema'] === $this->namespace) {
                continue;
            }
            $local = array_column($constraint['columns'], 0);
            $target = array_column($constraint['columns'], 1);
            $affected = false;
            if ($identity['local_schema'] === $this->namespace) {
                $table = $identity['local_table'];
                $projections = array_column($generated[$table] ?? [], 'column_name');
                $affected = (bool)array_intersect($local, $widen[$table] ?? [])
                    || (bool)array_intersect($local, $projections);
                // The existing widening producer drops a generated supporting
                // index even when the FK's local columns themselves stay wide.
                foreach ($projections === [] ? [] : (($tables[$table] ?? null)?->getIndexes() ?? []) as $index) {
                    if (array_intersect($index->getColumns(), $projections)
                        && array_slice($index->getColumns(), 0, count($local)) === $local) {
                        $affected = true;
                    }
                }
            }
            if ($identity['target_schema'] === $this->namespace) {
                $table = $identity['target_table'];
                $affected = $affected || (bool)array_intersect($target, $widen[$table] ?? [])
                    || (bool)array_intersect($target, array_column($generated[$table] ?? [], 'column_name'));
            }
            if ($affected) {
                throw new \RuntimeException('Identifier adoption cannot change a foreign key outside its configured namespace: '
                    . json_encode(['owner' => [$identity['local_schema'], $identity['local_table'], $identity['constraint_name']],
                        'columns' => $constraint['columns'], 'target' => [$identity['target_schema'], $identity['target_table']]], JSON_THROW_ON_ERROR));
            }
        }
    }
}
