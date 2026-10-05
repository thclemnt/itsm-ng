<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** Native incoming items_id references for one read-only migration planning call. */
final class IncomingProjectionReferences
{
    private ?array $references = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** Fresh native references for one read-only inspection scope, never across DDL. */
    public static function mysqlSnapshots(Connection $connection, array $tables): array
    {
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            throw new \InvalidArgumentException('MySQL native incoming snapshots require the actual MySQL platform.');
        }
        if ($tables === []) {
            return [];
        }
        $snapshots = array_fill_keys($tables, []);
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $rows = $connection->fetchAllAssociative('SELECT CONSTRAINT_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, (REFERENCED_COLUMN_NAME=?) AS selected_projection FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN (' . $placeholders . ') ORDER BY REFERENCED_TABLE_NAME, CONSTRAINT_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION', ['items_id', ...$tables]);
        $constraints = [];
        foreach ($rows as $row) {
            $selected = $row['selected_projection'];
            if (!in_array($selected, [false, true, 0, 1, '0', '1'], true)) {
                throw new \RuntimeException('Invalid native projection reference selection');
            }
            unset($row['selected_projection']);
            $target = $row['REFERENCED_TABLE_NAME'];
            if (!array_key_exists($target, $snapshots)) {
                throw new \RuntimeException('Native referenced table spelling differs from the requested schema snapshot');
            }
            $key = json_encode([$target, $row['CONSTRAINT_SCHEMA'], $row['TABLE_NAME'], $row['CONSTRAINT_NAME']], JSON_THROW_ON_ERROR);
            $constraints[$key]['rows'][] = $row;
            $constraints[$key]['selected'] = ($constraints[$key]['selected'] ?? false) || (bool)$selected;
        }
        foreach ($constraints as $constraint) {
            if (!$constraint['selected']) {
                continue;
            }
            $first = $constraint['rows'][0];
            $actions = $connection->fetchAllAssociative('SELECT UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME=?', [$first['CONSTRAINT_SCHEMA'], $first['TABLE_NAME'], $first['CONSTRAINT_NAME']]);
            foreach ($constraint['rows'] as $row) {
                foreach ($actions as $action) {
                    $snapshots[$first['REFERENCED_TABLE_NAME']][] = array_merge($row, $action);
                }
            }
        }
        return $snapshots;
    }

    /** Standalone preservation after DDL/callbacks always obtains a fresh snapshot. */
    public static function mysqlReferences(Connection $connection, string $table): array
    {
        return self::mysqlSnapshots($connection, [$table])[$table];
    }

    public function has(string $schema, string $table): bool
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->references ??= [];
            if (!array_key_exists($table, $this->references[$schema] ?? [])) {
                // Scope the referenced target only. A consumer in another
                // database still owns its incoming reference to this projection.
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT referenced_table_schema AS referenced_schema, referenced_table_name AS referenced_table '
                    . 'FROM information_schema.key_column_usage WHERE referenced_table_schema = ? '
                    . 'AND referenced_table_name = ? AND referenced_column_name = ?',
                    [$schema, $table, 'items_id']
                );
                $this->references[$schema][$table] = false;
                foreach ($rows as $reference) {
                    // Catalogue collation must not merge distinct native names.
                    if ($reference['referenced_schema'] === $schema && $reference['referenced_table'] === $table) {
                        $this->references[$schema][$table] = true;
                        break;
                    }
                }
            }
            return $this->references[$schema][$table];
        }
        if ($this->references === null) {
            // PostgreSQL constraint names need not be unique within a schema.
            // Resolve the referenced relation and attributes by their native IDs.
            // Keep references from every referencing schema/database, including
            // custom tables outside the application schema.
            $sql = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
                ? "SELECT n.nspname AS referenced_schema, r.relname AS referenced_table
                   FROM pg_catalog.pg_constraint f
                   JOIN pg_catalog.pg_class r ON r.oid = f.confrelid
                   JOIN pg_catalog.pg_namespace n ON n.oid = r.relnamespace
                   JOIN pg_catalog.pg_attribute a ON a.attrelid = r.oid AND a.attnum = ANY(f.confkey)
                   WHERE f.contype = 'f' AND a.attname = 'items_id'"
                : "SELECT referenced_table_schema AS referenced_schema, referenced_table_name AS referenced_table
                   FROM information_schema.key_column_usage WHERE referenced_column_name = 'items_id'";
            $this->references = [];
            foreach ($this->connection->fetchAllAssociative($sql) as $reference) {
                $this->references[$reference['referenced_schema']][$reference['referenced_table']] = true;
            }
        }
        return isset($this->references[$schema][$table]);
    }
}
