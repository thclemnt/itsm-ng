<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use RuntimeException;

/** Live native enforcement for subject policies produced by the current schema owner. */
final class NativeSubjectSchema
{
    public static function differences(Connection $connection, array $policies, ?array $checkSnapshot = null, bool $checksOnly = false): array
    {
        if (!$policies) {
            return [];
        }
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $columns = $checks = [];
        $parameters = [array_keys($policies)];
        $types = [ArrayParameterType::STRING];
        if ($mysql) {
            // One catalogue for all subject families; no per-table introspection
            // and no retained receipt is treated as the current declaration.
            try {
                $checkSnapshot ??= NativeCheckCatalog::snapshot($connection);
            } catch (RuntimeException $error) {
                return ['Native subject enforcement unavailable: ' . $error->getMessage()];
            }
            $checks = $checkSnapshot['checks'];
            $ansiQuotes = $checkSnapshot['ansi_quotes'];
            $rows = $connection->fetchAllAssociative(
                'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, '
                . 'EXTRA AS `generated`, GENERATION_EXPRESSION AS `expression` FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?)',
                $parameters,
                $types
            );
        } else {
            $ansiQuotes = false;
            $rows = $connection->fetchAllAssociative(
                'SELECT t.relname AS table_name, a.attname AS column_name, '
                . 'a.attgenerated AS generated, pg_get_expr(d.adbin, d.adrelid) AS expression, d.adbin::text AS native_nodes, '
                . 'a.attnum AS attribute_number, a.atttypid::text AS type_oid, a.atttypmod::text AS type_modifier, a.attcollation::text AS collation_oid, c.collisdeterministic AS deterministic '
                . 'FROM pg_catalog.pg_class t JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid '
                . 'LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = t.oid AND d.adnum = a.attnum '
                . 'LEFT JOIN pg_catalog.pg_collation c ON c.oid = a.attcollation '
                . 'WHERE t.relkind IN (\'r\', \'p\') AND a.attnum > 0 AND NOT a.attisdropped AND pg_catalog.pg_table_is_visible(t.oid) AND t.relname IN (?)',
                $parameters,
                $types
            );
            $checkSnapshot ??= NativeCheckCatalog::snapshot($connection);
            $checks = $checkSnapshot['checks'];
        }
        foreach ($rows as $column) {
            $columns[$column['table_name']][$column['column_name']] = $column;
        }
        return self::compare($policies, $columns, $checks, !$mysql, $ansiQuotes, $checksOnly);
    }

    /** Compare one operation's immutable catalog snapshot, retaining SQL literal and operator semantics. */
    public static function compare(array $policies, array $columns, array $checks, bool $postgres, bool $ansiQuotes = false, bool $checksOnly = false): array
    {
        $differences = [];
        foreach ($policies as $table => $fields) {
            foreach ($fields as $column => $policy) {
                $name = $policy['constraint'];
                $integerDiscriminators = $policy['integer_discriminators'] ?? [];
                $integerTypes = $policy['integer_types'] ?? [];
                $stringSelections = $policy['string_selections'] ?? [];
                $check = $checks[$table][$name] ?? null;
                $verifiedCheck = $check !== null && self::isTrue($check['enforced'] ?? null)
                    && (!$postgres || self::isTrue($check['validated'] ?? null))
                    && SubjectPolicyExpression::equivalent($policy['check'], $check['clause'] ?? '', $postgres, $ansiQuotes, integerDiscriminators: $integerDiscriminators, integerTypes: $integerTypes, stringSelections: $stringSelections);
                if ($verifiedCheck && $postgres && !empty($policy['open_string_fallback'])) {
                    $verifiedCheck = self::openStringNativeShape($policy, $check, $columns[$table] ?? [], false);
                }
                $native = $columns[$table][$column] ?? null;
                $stored = $postgres ? ($native['generated'] ?? null) === 's'
                    : preg_match('/(?:^|\s)STORED GENERATED(?:\s|$)/iD', $native['generated'] ?? '') === 1;
                if (!$checksOnly && (!$stored || !is_string($native['expression'] ?? null)
                    || !SubjectPolicyExpression::equivalent($policy['projection'], $native['expression'], $postgres, $ansiQuotes, $verifiedCheck ? $policy['check'] : null, $integerDiscriminators, $integerTypes, $stringSelections)
                    || ($postgres && !empty($policy['open_string_fallback'])
                        && !self::openStringNativeShape($policy, array_replace($check ?? [], ['native_nodes' => $native['native_nodes'] ?? null]), $columns[$table] ?? [], true)))) {
                    $differences[] = 'Changed or missing native subject projection: ' . $table . '.' . $column;
                }
                if (!$verifiedCheck) {
                    $differences[] = 'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $name;
                }
                if ($postgres) {
                    foreach ($policy['discriminators'] as $discriminator) {
                        if (!self::isTrue($columns[$table][$discriminator]['deterministic'] ?? null)) {
                            $differences[] = 'Expected deterministic subject discriminator: ' . $table . '.' . $discriminator;
                        }
                    }
                }
            }
        }
        return $differences;
    }


    /** The new singleton open branch admits bound builtin nodes, never user SQL identities. */
    private static function openStringNativeShape(array $policy, array $native, array $columns, bool $projection): bool
    {
        $choices = $policy['string_selections'] ?? [];
        $integerTypes = $policy['integer_types'] ?? [];
        if (count($choices) !== 1 || count(reset($choices)) !== 1 || count($integerTypes) !== 2) {
            return false;
        }
        $discriminator = array_key_first($choices);
        $wanted = [$discriminator, ...array_keys($integerTypes)];
        $nodes = $native['native_nodes'] ?? null;
        $operators = self::nativeList($native['reference_operator_oids'] ?? null);
        $identity = $native['reference_text_coercion'] ?? null;
        if (is_string($identity)) {
            $identity = json_decode($identity, true);
        }
        if (!is_string($nodes) || strlen($nodes) > 65536 || !is_array($identity)
            || !self::isTrue($identity['binary'] ?? null) || !$operators) {
            return false;
        }
        foreach (['varchar', 'text', 'smallint', 'integer', 'bigint', 'boolean'] as $name) {
            if (!is_string($identity[$name] ?? null) || !ctype_digit($identity[$name])) {
                return false;
            }
        }
        if (!$projection) {
            $checked = self::nativeList($native['checked_columns'] ?? null);
            sort($wanted);
            if ($checked === null) {
                return false;
            }
            sort($checked);
            if ($checked !== $wanted) {
                return false;
            }
        }
        $attributes = [];
        foreach ($wanted as $name) {
            $field = $columns[$name] ?? [];
            $type = $name === $discriminator ? $identity['varchar'] : ($identity[$integerTypes[$name]] ?? null);
            if (!ctype_digit((string)($field['attribute_number'] ?? ''))
                || ($field['type_oid'] ?? null) !== $type) {
                return false;
            }
            $attributes[(string)$field['attribute_number']] = [$type, (string)($field['collation_oid'] ?? ''), (string)($field['type_modifier'] ?? '')];
        }
        $collation = $columns[$discriminator]['collation_oid'] ?? null;
        if (!is_string($collation) || !ctype_digit($collation)) {
            return false;
        }
        preg_match_all('/\{([A-Z][A-Z_0-9]*)\b/', $nodes, $tags);
        $allowed = ['BOOLEXPR', 'OPEXPR', 'NULLTEST', 'VAR', 'CONST', 'RELABELTYPE'];
        if ($projection) {
            array_push($allowed, 'CASEEXPR', 'CASEWHEN', 'COALESCEEXPR', 'CASE', 'WHEN', 'COALESCE', 'FUNCEXPR');
        }
        if (!$tags[1] || array_diff($tags[1], $allowed)) {
            return false;
        }
        // Single declared string IN/NOT IN binds to scalar comparisons. The
        // generated zero may use only the proven implicit int4-to-int8 cast.
        // Array, other function/coercion, collation and subquery nodes are rejected.
        preg_match_all('/:opno ([0-9]+)(?=\s|})/', $nodes, $operations);
        if (!$operations[1] || array_diff($operations[1], array_map('strval', $operators))) {
            return false;
        }
        $functions = $native['subject_operator_functions'] ?? null;
        if (is_string($functions)) {
            $functions = json_decode($functions, true);
        }
        if (!is_array($functions) || !$functions) {
            return false;
        }
        preg_match_all('/\{OPEXPR :opno ([0-9]+) :opfuncid ([0-9]+) :opresulttype '
            . preg_quote($identity['boolean'], '/') . ' :opretset false :opcollid 0(?=\s|})/', $nodes, $bound, PREG_SET_ORDER);
        if (count($bound) !== count($operations[1])) {
            return false;
        }
        foreach ($bound as $operation) {
            if (($functions[$operation[1]] ?? null) !== $operation[2]) {
                return false;
            }
        }
        preg_match_all('/\{VAR\b[^{}]*}/', $nodes, $variables);
        $seen = [];
        foreach ($variables[0] as $variable) {
            if (!preg_match('/:varno 1 :varattno ([0-9]+) :vartype ([0-9]+)\b/', $variable, $field)
                || ($attributes[$field[1]][0] ?? null) !== $field[2]
                || !preg_match('/:vartypmod (-?[0-9]+)\b/', $variable, $modifier)
                || ($attributes[$field[1]][2] ?? null) !== $modifier[1]
                || !preg_match('/:varcollid ([0-9]+)\b/', $variable, $collationField)
                || ($attributes[$field[1]][1] ?? null) !== $collationField[1]
                || !preg_match('/:varlevelsup 0(?=\s|})/', $variable)) {
                return false;
            }
            $seen[$field[1]] = true;
        }
        if (count($seen) !== count($attributes)) {
            return false;
        }
        preg_match_all('/:consttype ([0-9]+)\b/', $nodes, $constants);
        if (array_diff($constants[1], array_values(array_intersect_key(
            $identity,
            array_fill_keys(['varchar', 'text', 'smallint', 'integer', 'bigint'], true)
        )))) {
            return false;
        }
        foreach (['inputcollid', 'constcollid', 'resultcollid'] as $field) {
            preg_match_all('/:' . $field . ' ([0-9]+)\b/', $nodes, $values);
            if (array_diff($values[1], ['0', $collation])) {
                return false;
            }
        }
        foreach (['casecollid', 'coalescecollid'] as $field) {
            preg_match_all('/:' . $field . ' ([0-9]+)\b/', $nodes, $values);
            if (array_diff($values[1], ['0'])) {
                return false;
            }
        }
        preg_match_all('/:(?:casetype|coalescetype) ([0-9]+)\b/', $nodes, $results);
        if (array_diff($results[1], [$identity['bigint']])) {
            return false;
        }
        preg_match_all('/\{FUNCEXPR\b/', $nodes, $casts);
        if ($casts[0]) {
            if (!$projection || !is_string($identity['integer_to_bigint'] ?? null)
                || !ctype_digit($identity['integer_to_bigint'])) {
                return false;
            }
            $zero = '\{CONST :consttype ' . preg_quote($identity['integer'], '/')
                . ' :consttypmod -1 :constcollid 0 :constlen 4 :constbyval true :constisnull false'
                . ' :location -?[0-9]+ :constvalue 4 \[ 0 0 0 0(?: 0 0 0 0)? ]}';
            $cast = '/\{FUNCEXPR :funcid ' . preg_quote($identity['integer_to_bigint'], '/')
                . ' :funcresulttype ' . preg_quote($identity['bigint'], '/')
                . ' :funcretset false :funcvariadic false :funcformat 2 :funccollid 0 :inputcollid 0'
                . ' :args \(' . $zero . '\) :location -?[0-9]+}/';
            if (preg_match_all($cast, $nodes) !== count($casts[0])) {
                return false;
            }
        }
        preg_match_all('/\{RELABELTYPE\b/', $nodes, $relabels);
        $pattern = '/\{RELABELTYPE :arg \{VAR\b[^{}]*} :resulttype '
            . preg_quote($identity['text'], '/') . ' :resulttypmod -1 :resultcollid '
            . preg_quote($collation, '/') . ' :relabelformat ' . ($projection ? '2' : '[12]') . ' :location -?[0-9]+}/';
        return preg_match_all($pattern, $nodes) === count($relabels[0]);
    }

    private static function nativeList(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) && array_is_list($value) ? $value : null;
    }

    private static function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true', 'YES'], true);
    }
}
