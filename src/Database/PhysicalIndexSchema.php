<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;

/** Physical lookup coverage: DBAL Table introspection invents FK backing indexes. */
final class PhysicalIndexSchema
{
    /** One native catalog read for this operation's explicitly owned tables. */
    public static function catalog(Connection $connection, array $tables): array
    {
        if (!$tables) {
            return [];
        }
        $platform = $connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;
        if ($mysql) {
            $visible = "IS_VISIBLE = 'YES'";
            if ($platform instanceof MariaDBPlatform) {
                // IGNORED first exists in 10.6; earlier supported MariaDB
                // versions cannot hide an index from the optimizer.
                $version = CheckConstraintSupport::version($connection->getServerVersion(), true);
                $visible = version_compare($version, '10.6', '>=') ? "IGNORED = 'NO'" : '1';
            }
            $sql = 'SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, '
                . 'SEQ_IN_INDEX AS position, COLUMN_NAME AS column_name, SUB_PART AS prefix_length, '
                . 'INDEX_TYPE AS access_method, (' . $visible . ') AS visible '
                . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?) '
                . 'ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX';
        } else {
            $sql = 'SELECT t.relname AS table_name, x.relname AS index_name, i.indisunique AS is_unique, '
                . 'i.indisprimary AS is_primary, i.indisvalid AS is_valid, i.indisready AS is_ready, '
                . 'am.amname AS access_method, i.indnkeyatts AS key_count, '
                . 'pg_get_expr(i.indpred, i.indrelid) AS predicate, pg_get_expr(i.indexprs, i.indrelid) AS expressions, '
                . 'k.position, a.attname AS column_name, opc.opcdefault AS default_operator_class, '
                . 'i.indcollation[k.position - 1] = a.attcollation AS column_collation, '
                . "COALESCE((to_jsonb(i)->>'indnullsnotdistinct')::boolean, false) AS nulls_not_distinct "
                . 'FROM pg_catalog.pg_class t JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace '
                . 'JOIN pg_catalog.pg_index i ON i.indrelid = t.oid JOIN pg_catalog.pg_class x ON x.oid = i.indexrelid '
                . 'JOIN pg_catalog.pg_am am ON am.oid = x.relam '
                . 'CROSS JOIN LATERAL generate_series(1, i.indnkeyatts) k(position) '
                . 'LEFT JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid AND a.attnum = i.indkey[k.position - 1] '
                . 'JOIN pg_catalog.pg_opclass opc ON opc.oid = i.indclass[k.position - 1] '
                . 'WHERE n.nspname = current_schema() AND t.relname IN (?) ORDER BY t.relname, x.relname, k.position';
        }
        $catalog = [];
        foreach ($connection->fetchAllAssociative($sql, [$tables], [\Doctrine\DBAL\ArrayParameterType::STRING]) as $row) {
            $index = &$catalog[$row['table_name']][$row['index_name']];
            if ($index === null) {
                $index = [
                    'columns' => [], 'lengths' => [],
                    'unique' => $mysql ? !(bool)$row['non_unique'] : self::isTrue($row['is_unique']),
                    'primary' => $mysql ? $row['index_name'] === 'PRIMARY' : self::isTrue($row['is_primary']),
                    'method' => strtolower($row['access_method']),
                    'usable' => $mysql ? self::isTrue($row['visible']) : self::isTrue($row['is_valid']) && self::isTrue($row['is_ready']),
                    'predicate' => $row['predicate'] ?? null,
                    'expressions' => $row['expressions'] ?? null,
                    'standard_equality' => true,
                    'nulls_not_distinct' => !$mysql && self::isTrue($row['nulls_not_distinct']),
                ];
            }
            $index['standard_equality'] = $index['standard_equality'] && ($mysql
                || (self::isTrue($row['default_operator_class']) && self::isTrue($row['column_collation'])));
            $index['columns'][] = $row['column_name'];
            $index['lengths'][] = isset($row['prefix_length']) ? (int)$row['prefix_length'] : null;
            unset($index);
        }
        return $catalog;
    }

    /** Names are not proof; ordinary wider indexes may cover an ordered lookup prefix. */
    public static function covers(Index $required, array $physical): bool
    {
        $columns = array_map(static fn (string $column): string => trim($column, '`"'), $required->getColumns());
        $flags = array_map('strtolower', $required->getFlags());
        $method = $flags === [] ? 'btree' : ($flags === ['fulltext'] ? 'fulltext' : ($flags === ['spatial'] ? 'rtree' : null));
        if (!$physical['usable'] || !$physical['standard_equality'] || $method === null || $physical['method'] !== $method
            || $physical['expressions'] !== null || $physical['predicate'] !== ($required->getOptions()['where'] ?? null)
            || array_slice($physical['columns'], 0, count($columns)) !== $columns) {
            return false;
        }
        $exact = $required->isUnique() || $required->isPrimary() || $method !== 'btree';
        if (($exact && $physical['columns'] !== $columns)
            || ($required->isUnique() && (!$physical['unique'] || $physical['nulls_not_distinct'])) || ($required->isPrimary() && !$physical['primary'])) {
            return false;
        }
        $lengths = $required->getOptions()['lengths'] ?? [];
        foreach ($columns as $position => $column) {
            $wanted = isset($lengths[$position]) ? (int)$lengths[$position] : null;
            $actual = $physical['lengths'][$position];
            // A partial string index cannot cover a full-column lookup. Unique
            // prefix semantics require equality; ordinary longer prefixes suffice.
            if (($exact && $actual !== $wanted)
                || (!$exact && $actual !== null && ($wanted === null || $actual < $wanted))) {
                return false;
            }
        }
        return true;
    }

    public static function missing(array $required, array $catalog): array
    {
        $missing = [];
        foreach ($required as $table => $indexes) {
            foreach ($indexes as $index) {
                foreach ($catalog[$table] ?? [] as $physical) {
                    if (self::covers($index, $physical)) {
                        continue 2;
                    }
                }
                $missing[$table][] = $index;
            }
        }
        return $missing;
    }

    public static function differences(Connection $connection, Schema $expected): array
    {
        $required = [];
        foreach ($expected->getTables() as $table) {
            $required[$table->getName()] = $table->getIndexes();
        }
        $catalog = self::catalog($connection, array_keys($required));
        foreach ($catalog as $table => $physicalIndexes) {
            foreach ($physicalIndexes as $name => $physical) {
                if (!$physical['unique']) {
                    continue;
                }
                // A required UNIQUE/PRIMARY may also support an FK lookup.
                // Replacing an ordinary index with undeclared uniqueness (even
                // under another name) instead changes the permitted row set.
                foreach ($required[$table] as $declared) {
                    if ($declared->isUnique() && self::covers($declared, $physical)) {
                        continue 2;
                    }
                }
                unset($catalog[$table][$name]);
            }
        }
        $differences = [];
        foreach (self::missing($required, $catalog) as $table => $indexes) {
            foreach ($indexes as $index) {
                $differences[] = 'Missing physical index coverage: ' . $table . '.' . $index->getName();
            }
        }
        return $differences;
    }

    private static function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
