<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;

/** Physical lookup coverage: DBAL Table introspection invents FK backing indexes. */
final class PhysicalIndexSchema
{
    /** One native catalog read for this operation's explicitly owned tables. */
    public static function catalog(Connection $connection, array $tables, array $nativePrefixes = []): array
    {
        if (!$tables) {
            return [];
        }
        $platform = $connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;
        if ($mysql) {
            $visible = 'IS_VISIBLE';
            $visibleValue = 'YES';
            if ($platform instanceof MariaDBPlatform) {
                // IGNORED first exists in 10.6; earlier supported MariaDB
                // versions cannot hide an index from the optimizer.
                $version = CheckConstraintSupport::version($connection->getServerVersion(), true);
                $visible = version_compare($version, '10.6', '>=') ? 'IGNORED' : "'NO'";
                $visibleValue = 'NO';
            }
            $sql = 'SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, '
                . 'SEQ_IN_INDEX AS position, COLUMN_NAME AS column_name, SUB_PART AS prefix_length, '
                . 'INDEX_TYPE AS access_method, ' . $visible . ' AS visible '
                . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?) '
                . 'ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX';
        } else {
            $prefixColumns = [];
            foreach ($nativePrefixes as $table => $indexes) {
                foreach ($indexes as $name => $policy) {
                    foreach ($policy['columns'] as $position => $column) {
                        $prefixColumns[] = ['table_name' => $table, 'index_name' => $name, 'position' => $position + 1, 'column_name' => $column, 'source_type' => $policy['sourceTypes'][$position]];
                    }
                }
            }
            $sql = 'SELECT t.relname AS table_name, x.relname AS index_name, i.indisunique AS is_unique, '
                . 'i.indisprimary AS is_primary, i.indisvalid AS is_valid, i.indisready AS is_ready, i.indislive AS is_live, '
                . 'am.amname AS access_method, i.indnkeyatts AS key_count, i.indnatts AS total_count, '
                . 'pg_get_indexdef(i.indexrelid, k.position, true) AS key_expression, i.indexprs::text AS expression_nodes, '
                . 'i.indkey[k.position - 1] AS attribute_number, i.indoption[k.position - 1] AS key_options, '
                . 'x.relnamespace = t.relnamespace AS index_namespace, p.column_name AS expected_column, p.source_type AS expected_source_type, '
                . 'source.attname AS source_column, source.attcollation = i.indcollation[k.position - 1] AS prefix_collation, '
                . "CASE p.source_type WHEN 'varchar' THEN source.atttypid = 'pg_catalog.varchar'::regtype::oid "
                . "WHEN 'text' THEN source.atttypid = 'pg_catalog.text'::regtype::oid ELSE false END AS source_type_matches, "
                . "'pg_catalog.left(text,integer)'::regprocedure::oid AS prefix_function_oid, "
                . "opn.nspname = 'pg_catalog' AND opc.opcname = 'text_ops' AND opc.opcintype = 'pg_catalog.text'::regtype::oid AS prefix_operator_class, "
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
                . 'JOIN pg_catalog.pg_namespace opn ON opn.oid = opc.opcnamespace '
                . 'LEFT JOIN jsonb_to_recordset(?::jsonb) AS p(table_name text, index_name text, position integer, column_name text, source_type text) '
                . 'ON p.table_name = t.relname AND p.index_name = x.relname AND p.position = k.position '
                . 'LEFT JOIN pg_catalog.pg_attribute source ON source.attrelid = t.oid AND source.attname = p.column_name '
                . 'AND source.attnum > 0 AND NOT source.attisdropped '
                . 'WHERE n.nspname = current_schema() AND t.relname IN (?) ORDER BY t.relname, x.relname, k.position';
        }
        $catalog = [];
        $parameters = $mysql ? [$tables] : [json_encode($prefixColumns, JSON_THROW_ON_ERROR), $tables];
        $parameterTypes = $mysql ? [ArrayParameterType::STRING] : [ParameterType::STRING, ArrayParameterType::STRING];
        foreach ($connection->fetchAllAssociative($sql, $parameters, $parameterTypes) as $row) {
            $index = &$catalog[$row['table_name']][$row['index_name']];
            if ($index === null) {
                $index = [
                    'columns' => [], 'lengths' => [],
                    'unique' => $mysql ? !(bool)$row['non_unique'] : self::isTrue($row['is_unique']),
                    'primary' => $mysql ? $row['index_name'] === 'PRIMARY' : self::isTrue($row['is_primary']),
                    'method' => strtolower($row['access_method']),
                    // Compare native visibility tokens in PHP: MySQL metadata and
                    // connection literals can otherwise have incompatible collations.
                    'usable' => $mysql ? $row['visible'] === $visibleValue : self::isTrue($row['is_valid']) && self::isTrue($row['is_ready']),
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
            if (!$mysql && ($row['expected_column'] ?? null) !== null) {
                $index['native_prefix'] ??= [
                    'key_count' => (int)$row['key_count'], 'total_count' => (int)$row['total_count'],
                    'namespace' => self::isTrue($row['index_namespace']), 'live' => self::isTrue($row['is_live']),
                    'function_oid' => (string)$row['prefix_function_oid'], 'nodes' => $row['expression_nodes'], 'keys' => [],
                ];
                $index['native_prefix']['keys'][] = [
                    'source' => $row['source_column'], 'source_type' => $row['expected_source_type'], 'source_type_matches' => self::isTrue($row['source_type_matches']),
                    'expression' => $row['key_expression'], 'attribute' => (int)$row['attribute_number'],
                    'options' => (int)$row['key_options'], 'collation' => self::isTrue($row['prefix_collation']),
                    'opclass' => self::isTrue($row['default_operator_class']) && self::isTrue($row['prefix_operator_class']),
                ];
            }
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

    public static function differences(Connection $connection, Schema $expected, array $nativePrefixes = []): array
    {
        $required = [];
        foreach ($expected->getTables() as $table) {
            $required[$table->getName()] = $table->getIndexes();
        }
        $catalog = self::catalog($connection, array_keys($required), $nativePrefixes);
        $differences = [];
        foreach ($nativePrefixes as $table => $indexes) {
            foreach ($indexes as $name => $policy) {
                if (!isset($catalog[$table][$name]) || !self::coversNativePrefix($policy, $catalog[$table][$name])) {
                    $differences[] = 'Missing or changed native prefix index: ' . $table . '.' . $name;
                }
            }
        }
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
        foreach (self::missing($required, $catalog) as $table => $indexes) {
            foreach ($indexes as $index) {
                $differences[] = 'Missing physical index coverage: ' . $table . '.' . $index->getName();
            }
        }
        return $differences;
    }

    /** A finite left(text-or-varchar, positive integer) contract, independently checked against native identity. */
    public static function coversNativePrefix(array $policy, array $physical): bool
    {
        $native = $physical['native_prefix'] ?? null;
        $count = count($policy['columns']);
        if ($native === null || !$physical['usable'] || $physical['unique'] || $physical['primary']
            || $physical['method'] !== 'btree' || $physical['predicate'] !== null || $physical['expressions'] === null
            || !$native['namespace'] || !$native['live'] || $native['key_count'] !== $count || $native['total_count'] !== $count
            || count($native['keys']) !== $count || count($policy['lengths']) !== $count
            || count($policy['sourceTypes']) !== $count
            || !is_string($native['nodes'])) {
            return false;
        }
        // A deparsed function name is not its identity: a visible user-defined
        // left() must not satisfy ownership. Every function node must be the builtin.
        preg_match_all('/:funcid ([0-9]+)(?=\s|})/', $native['nodes'], $functions);
        if (count($functions[1]) !== $count || array_filter($functions[1], static fn (string $oid): bool => $oid !== $native['function_oid'])) {
            return false;
        }
        foreach ($policy['columns'] as $position => $column) {
            $key = $native['keys'][$position];
            $length = $policy['lengths'][$position];
            if ($key['source'] !== $column || !in_array($policy['sourceTypes'][$position], ['varchar', 'text'], true)
                || $key['source_type'] !== $policy['sourceTypes'][$position] || !$key['source_type_matches'] || !$key['opclass'] || !$key['collation']
                || $key['attribute'] !== 0 || $key['options'] !== 0 || !is_int($length) || $length <= 0
                || !self::isNativePrefixExpression($key['expression'], $column, $length)) {
                return false;
            }
        }
        return true;
    }

    /** Match complete finite native renderings; never discard arbitrary casts/operators/collations. */
    private static function isNativePrefixExpression(string $expression, string $column, int $length): bool
    {
        if (strlen($expression) > 4096) {
            return false;
        }
        $quoted = preg_quote('"' . str_replace('"', '""', $column) . '"', '/');
        $identifier = preg_match('/\A[a-z_][a-z_0-9]*\z/D', $column)
            ? '(?:' . $quoted . '|' . preg_quote($column, '/') . ')' : $quoted;
        $source = '(?:' . $identifier . '|\(\s*' . $identifier . '\s*\))';
        // varchar->text is binary widening; its actual source type, function OID,
        // operator class and inherited collation are separately verified above.
        $operand = '(?:' . $source . '(?:\s*::\s*(?:pg_catalog\.)?text)?|\(\s*' . $source . '\s*::\s*(?:pg_catalog\.)?text\s*\))';
        $function = '(?:(?:pg_catalog\.)?(?:left|"left"))';
        $call = $function . '\(\s*' . $operand . '\s*,\s*' . $length . '\s*\)';
        return preg_match('/\A\s*(?:' . $call . '|\(\s*' . $call . '\s*\))\s*\z/D', $expression) === 1;
    }

    private static function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
