<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;

/** Current boolean domain inspection derives from the owning mapped properties. */
final class BooleanDomainSchema
{
    public static function name(string $table, string $column): string
    {
        $name = $table . '_' . $column . '_boolean';
        return strlen($name) <= 63 ? $name : 'boolean_' . substr(hash('sha256', $table . '.' . $column), 0, 40);
    }

    public static function expression(AbstractPlatform $platform, string $column, bool $nullable): string
    {
        $field = $platform->quoteIdentifier($column);
        return $field . ($nullable ? ' IS NULL OR ' : ' IS NOT NULL AND ') . $field . ' IN (0, 1)';
    }

    /** One native name/type snapshot per call; no persistent schema cache after DDL. */
    public static function catalog(Connection $connection): array
    {
        CheckConstraintSupport::assertSupported($connection);
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $query = $mysql
            ? 'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            : 'SELECT columns.table_name, columns.column_name, columns.data_type, columns.is_nullable, columns.column_default FROM information_schema.columns '
                . 'WHERE columns.table_schema = ANY(current_schemas(false)) AND EXISTS (SELECT 1 FROM pg_catalog.pg_class c '
                . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = columns.table_name '
                . 'AND n.nspname = columns.table_schema AND pg_catalog.pg_table_is_visible(c.oid))';
        $columns = [];
        foreach ($connection->fetchAllAssociative($query) as $column) {
            $columns[$column['table_name']][$column['column_name']] = $column;
        }
        $checks = [];
        if ($mysql) {
            $enforced = $connection->getDatabasePlatform() instanceof MySQLPlatform ? 'tc.ENFORCED' : "'YES'";
            $query = 'SELECT tc.TABLE_NAME AS table_name, tc.CONSTRAINT_NAME AS constraint_name, cc.CHECK_CLAUSE AS clause, ' . $enforced . ' AS enforced '
                . 'FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc '
                . 'ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
                . ($connection->getDatabasePlatform() instanceof MySQLPlatform ? '' : 'AND cc.TABLE_NAME = tc.TABLE_NAME ')
                . "WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.CONSTRAINT_TYPE = 'CHECK'";
            foreach ($connection->fetchAllAssociative($query) as $check) {
                $checks[$check['table_name']][$check['constraint_name']] = $check;
            }
        }
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
        return ['mysql' => $mysql, 'columns' => $columns, 'checks' => $checks];
    }

    /** @return list<string> Read-only logical checks alongside DBAL structural comparison. */
    public static function differences(Connection $connection, Schema $expected): array
    {
        try {
            $catalog = self::catalog($connection);
        } catch (\RuntimeException $error) {
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
                } elseif (!BooleanCheckExpression::matches($check['clause'], $column, $nullable)) {
                    $differences[] = 'Changed boolean domain CHECK: ' . $table . '.' . $name;
                } elseif ($check['enforced'] !== 'YES') {
                    $differences[] = 'Unenforced boolean domain CHECK: ' . $table . '.' . $name;
                }
            }
        }
        return $differences;
    }
}
