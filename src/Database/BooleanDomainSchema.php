<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
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
        // MariaDB formats CHECK_CLAUSE using the current session's identifier
        // quoting, regardless of the mode when the CHECK was originally created.
        // Snapshot that actual interpretation; never change modes to inspect it.
        $ansiQuotes = $mysql && in_array('ANSI_QUOTES', explode(',', (string)$connection->fetchOne('SELECT @@SESSION.sql_mode')), true);
        $columns = [];
        foreach ($connection->fetchAllAssociative($query) as $column) {
            $columns[$column['table_name']][$column['column_name']] = $column;
        }
        $checks = self::readChecks($connection);
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

    /** Fresh physical CHECK inspection; never reuse this across DDL or callbacks. */
    public static function checks(Connection $connection, string $table): array
    {
        CheckConstraintSupport::assertSupported($connection);
        return self::readChecks($connection, $table);
    }

    /** The full catalogue and selected-table reader share native ownership. */
    private static function readChecks(Connection $connection, ?string $table = null): array
    {
        $checks = [];
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            if ($connection->getDatabasePlatform() instanceof MariaDBPlatform) {
                // MariaDB owns each CHECK's table in this native catalogue.
                // Enforcement is session-wide and was asserted above.
                $query = "SELECT cc.TABLE_NAME AS table_name, cc.CONSTRAINT_NAME AS constraint_name, cc.CHECK_CLAUSE AS clause, 'YES' AS enforced "
                    . 'FROM information_schema.CHECK_CONSTRAINTS cc WHERE cc.CONSTRAINT_SCHEMA = DATABASE()';
                $parameters = [];
                if ($table !== null) {
                    $query .= ' AND cc.TABLE_NAME = ?';
                    $parameters[] = $table;
                }
                foreach ($connection->fetchAllAssociative($query, $parameters) as $check) {
                    $checks[$check['table_name']][$check['constraint_name']] = $check;
                }
            } else {
                $checks = self::readMySQLChecks($connection, $table);
            }
        }
        ksort($checks);
        foreach ($checks as &$tableChecks) {
            ksort($tableChecks);
        }
        unset($tableChecks);
        return $checks;
    }

    private static function readMySQLChecks(Connection $connection, ?string $table): array
    {
        // MySQL CHECK names are unique within a schema. Inspect the native views
        // independently: joining their lateral owner view can omit whole tables.
        $query = 'SELECT TABLE_NAME AS table_name, CONSTRAINT_NAME AS constraint_name, ENFORCED AS enforced '
            . "FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'";
        $parameters = [];
        if ($table !== null) {
            $query .= ' AND TABLE_NAME = ?';
            $parameters[] = $table;
        }
        $owners = [];
        foreach ($connection->fetchAllAssociative($query, $parameters) as $owner) {
            $name = $owner['constraint_name'];
            if (isset($owners[$name])) {
                throw new RuntimeException('Ambiguous native CHECK ownership: ' . $name);
            }
            $owners[$name] = $owner;
        }
        if ($table !== null && $owners === []) {
            return [];
        }
        $query = 'SELECT CONSTRAINT_NAME AS constraint_name, CHECK_CLAUSE AS clause '
            . 'FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()';
        $parameters = $types = [];
        if ($table !== null) {
            $query .= ' AND CONSTRAINT_NAME IN (?)';
            $parameters = [array_keys($owners)];
            $types = [ArrayParameterType::STRING];
        }
        $clauses = [];
        foreach ($connection->fetchAllAssociative($query, $parameters, $types) as $check) {
            $name = $check['constraint_name'];
            if (array_key_exists($name, $clauses)) {
                throw new RuntimeException('Ambiguous native CHECK clause: ' . $name);
            }
            $clauses[$name] = $check['clause'];
        }
        $checks = [];
        foreach ($owners as $name => $owner) {
            if (!isset($clauses[$name])) {
                throw new RuntimeException('Native CHECK owner lacks a clause: ' . $owner['table_name'] . '.' . $name);
            }
            $checks[$owner['table_name']][$name] = [
                'table_name' => $owner['table_name'],
                'constraint_name' => $name,
                'clause' => $clauses[$name],
                'enforced' => $owner['enforced'],
            ];
            unset($clauses[$name]);
        }
        if ($clauses !== []) {
            throw new RuntimeException('Native CHECK clause lacks an owner: ' . array_key_first($clauses));
        }
        return $checks;
    }

    /** @return list<string> Read-only logical checks alongside DBAL structural comparison. */
    public static function differences(Connection $connection, Schema $expected): array
    {
        try {
            $catalog = self::catalog($connection);
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
