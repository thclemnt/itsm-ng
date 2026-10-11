<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use RuntimeException;

/** Current boolean domain inspection derives from the owning mapped properties. */
final class BooleanDomainSchema
{
    public static function name(string $table, string $column): string
    {
        $name = $table . '_' . $column . '_boolean';
        return strlen($name) <= 63 ? $name : 'boolean_' . substr(hash('sha256', $table . '.' . $column), 0, 40);
    }

    /** One native name/type snapshot per call; no persistent schema cache after DDL. */
    public static function catalog(Connection $connection, ?array $checkSnapshot = null): array
    {
        $checkSnapshot ??= NativeCheckCatalog::snapshot($connection);
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $query = $mysql
            ? 'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            : 'SELECT columns.table_name, columns.column_name, columns.data_type, columns.is_nullable, columns.column_default FROM information_schema.columns '
                . 'WHERE columns.table_schema = ANY(current_schemas(false)) AND EXISTS (SELECT 1 FROM pg_catalog.pg_class c '
                . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = columns.table_name '
                . 'AND n.nspname = columns.table_schema AND pg_catalog.pg_table_is_visible(c.oid))';
        // MariaDB formats CHECK_CLAUSE using the current session's identifier
        // quoting, regardless of the mode when the CHECK was originally created.
        // Snapshot that actual interpretation; never change modes to inspect it.
        $ansiQuotes = $checkSnapshot['ansi_quotes'];
        $columns = [];
        foreach ($connection->fetchAllAssociative($query) as $column) {
            $columns[$column['table_name']][$column['column_name']] = $column;
        }
        $checks = $checkSnapshot['checks'];
        // Catalogue row order is not a schema property. Stable maps make
        // read-only comparisons and retry snapshots independent of DDL order.
        ksort($columns);
        foreach ($columns as &$tableColumns) {
            ksort($tableColumns);
        }
        unset($tableColumns);
        ksort($checks);
        foreach ($checks as &$tableChecks) {
            ksort($tableChecks);
        }
        unset($tableChecks);
        return ['mysql' => $mysql, 'ansi_quotes' => $ansiQuotes, 'columns' => $columns, 'checks' => $checks];
    }

    /** Compatibility entry point retained for frozen migration callers. */
    public static function checks(Connection $connection, string $table): array
    {
        return NativeCheckCatalog::snapshot($connection, $table)['checks'];
    }

    /** @return list<string> Read-only logical checks alongside DBAL structural comparison. */
    public static function differences(Connection $connection, Schema $expected, ?array $checkSnapshot = null): array
    {
        try {
            $catalog = self::catalog($connection, $checkSnapshot);
        } catch (RuntimeException $error) {
            return ['Boolean domain enforcement unavailable: ' . $error->getMessage()];
        }
        $differences = [];
        foreach (EntityRegistry::booleanColumns() as $table => $columns) {
            if (!$expected->hasTable($table)) {
                continue;
            }
            foreach (EntityRegistry::booleanFields($table) as $column => $nullable) {
                // General DBAL comparison already reports missing tables/columns.
                if (!$expected->getTable($table)->hasColumn($column) || !isset($catalog['columns'][$table][$column])) {
                    continue;
                }
                if (!$catalog['mysql']) {
                    if ($catalog['columns'][$table][$column]['data_type'] !== 'boolean') {
                        $differences[] = 'Expected native boolean: ' . $table . '.' . $column;
                    }
                    continue;
                }
                $name = self::name($table, $column);
                $check = $catalog['checks'][$table][$name] ?? null;
                if ($check === null) {
                    $differences[] = 'Missing boolean domain CHECK: ' . $table . '.' . $name;
                } elseif (!BooleanCheckExpression::matches($check['clause'], $column, $nullable, $catalog['ansi_quotes'])) {
                    $differences[] = 'Changed boolean domain CHECK: ' . $table . '.' . $name;
                } elseif ($check['enforced'] !== 'YES') {
                    $differences[] = 'Unenforced boolean domain CHECK: ' . $table . '.' . $name;
                }
            }
        }
        return $differences;
    }
}
