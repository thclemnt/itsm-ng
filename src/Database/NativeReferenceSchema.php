<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Mapping\ReferenceKind;

/** Named selection CHECKs derive from their actual owning property metadata. */
final class NativeReferenceSchema
{
    /** Pure comparison of the same operation snapshot used by other native families. */
    public static function compare(array $policies, array $snapshot): array
    {
        $differences = [];
        foreach ($policies as $table => $fields) {
            foreach ($fields as $policy) {
                $check = $snapshot['checks'][$table][$policy['constraint']] ?? null;
                if ($check === null || !self::matches($policy, $check, $snapshot)) {
                    $differences[] = 'Changed, missing or unenforced native ' . (isset($policy['kind']) ? '' : 'inherited ') . 'reference CHECK: ' . $table . '.' . $policy['constraint'];
                }
            }
        }
        return $differences;
    }

    private static function matches(array $policy, array $check, array $snapshot): bool
    {
        $postgres = !$snapshot['mysql'];
        if (!self::isTrue($check['enforced'] ?? null) || ($postgres && !self::isTrue($check['validated'] ?? null))
            || !is_string($check['clause'] ?? null)) {
            return false;
        }
        if (isset($policy['kind'])) {
            if (!in_array($policy['kind'], [ReferenceKind::RootParent->value, ReferenceKind::EmptySelection->value], true)) {
                return false;
            }
            return (!$postgres || self::integerSelectionShape($policy, $check))
                && SubjectPolicyExpression::equivalent(
                    $policy['check'],
                    $check['clause'],
                    $postgres,
                    $snapshot['ansi_quotes'],
                    integerTypes: $policy['integer_types'],
                    integerPairs: $policy['integer_pairs'],
                    booleanColumns: $policy['boolean_columns']
                );
        }
        if ($postgres) {
            $columns = self::nativeList($check['checked_columns'] ?? null);
            $operators = self::nativeList($check['reference_operator_oids'] ?? null);
            $nodes = $check['native_nodes'] ?? null;
            if ($columns === null || $operators === null || !is_string($nodes) || strlen($nodes) > 65536) {
                return false;
            }
            $wanted = [$policy['mode_column'], $policy['selected_column']];
            sort($columns);
            sort($wanted);
            if ($columns !== $wanted) {
                return false;
            }
            // This finite family has boolean junctions, literal choices, null tests,
            // variables and binary varchar-to-text relabels, never user functions.
            preg_match_all('/\{([A-Z][A-Z_0-9]*)\b/', $nodes, $tags);
            if (!$tags[1] || array_diff($tags[1], ['BOOLEXPR', 'SCALARARRAYOPEXPR', 'OPEXPR', 'NULLTEST', 'VAR', 'CONST', 'ARRAYEXPR', 'ARRAY', 'RELABELTYPE', 'ARRAYCOERCEEXPR', 'CASETESTEXPR'])) {
                return false;
            }
            if (!self::binaryModeArrayCoercions($nodes, $tags[1], $check['reference_text_coercion'] ?? null)) {
                return false;
            }
            preg_match_all('/:opno ([0-9]+)(?=\s|})/', $nodes, $ids);
            if (!$ids[1] || array_diff($ids[1], array_map('strval', $operators))) {
                return false;
            }
        }
        return SubjectPolicyExpression::equivalent(
            $policy['check'],
            $check['clause'],
            $postgres,
            $snapshot['ansi_quotes'],
            integerTypes: $policy['integer_types'],
            stringSelections: $policy['string_selections']
        );
    }

    /** Two declared scalar families, bound to attributes and builtin operators in this same snapshot. */
    private static function integerSelectionShape(array $policy, array $check): bool
    {
        $columns = self::nativeList($check['checked_columns'] ?? null);
        $identity = self::nativeObject($check['selection_column_identity'] ?? null);
        $types = self::nativeObject($check['reference_text_coercion'] ?? null);
        $operators = self::nativeObject($check['selection_operator_bindings'] ?? null);
        $nodes = $check['native_nodes'] ?? null;
        if ($columns === null || $identity === null || $types === null || $operators === null
            || !is_string($nodes) || strlen($nodes) > 65536) {
            return false;
        }
        $wanted = [...array_keys($policy['integer_types']), ...$policy['boolean_columns']];
        $actual = array_keys($identity);
        sort($columns);
        sort($actual);
        sort($wanted);
        if ($columns !== $wanted || $actual !== $wanted) {
            return false;
        }
        $attributes = [];
        $booleanAttributes = [];
        foreach ($wanted as $column) {
            $type = in_array($column, $policy['boolean_columns'], true) ? 'boolean' : $policy['integer_types'][$column];
            $oid = $types[$type] ?? null;
            $field = $identity[$column];
            if (!is_array($field) || !ctype_digit((string)$oid) || !ctype_digit((string)($field['attribute_number'] ?? ''))
                || (int)$field['attribute_number'] <= 0 || isset($attributes[(int)$field['attribute_number']])
                || (string)($field['type_oid'] ?? '') !== (string)$oid
                || (string)($field['type_modifier'] ?? '') !== '-1' || (string)($field['collation_oid'] ?? '') !== '0'
                || !is_bool($field['not_null'] ?? null) || $field['not_null'] !== !$policy['column_nullable'][$column]
                || ($field['generated'] ?? null) !== '') {
                return false;
            }
            $attributes[(int)$field['attribute_number']] = (string)$oid;
            if ($type === 'boolean') {
                $booleanAttributes[] = (int)$field['attribute_number'];
            }
        }
        $boolean = $types['boolean'] ?? null;
        if (!ctype_digit((string)$boolean)) {
            return false;
        }
        preg_match_all('/\{([A-Z][A-Z_0-9]*)\b/', $nodes, $tags);
        if (!$tags[1] || array_diff($tags[1], ['BOOLEXPR', 'OPEXPR', 'NULLTEST', 'VAR', 'CONST'])) {
            return false;
        }
        // Attribute identities, types and collations are checked on every VAR,
        // not merely on the CHECK's unordered conkey list.
        $variable = '\{VAR :varno 1 :varattno (?<attribute>[1-9][0-9]*) :vartype (?<type>[0-9]+) :vartypmod -1 :varcollid 0(?: :varnullingrels \(b\))? :varlevelsup 0(?: :varreturningtype 0)? :varnosyn 1 :varattnosyn \k<attribute> :location -?[0-9]+\}';
        preg_match_all('/' . $variable . '/', $nodes, $vars, PREG_SET_ORDER);
        if (count($vars) !== count(array_filter($tags[1], static fn (string $tag): bool => $tag === 'VAR'))) {
            return false;
        }
        $seen = [];
        foreach ($vars as $var) {
            if (($attributes[(int)$var['attribute']] ?? null) !== $var['type']) {
                return false;
            }
            $seen[(int)$var['attribute']] = true;
        }
        if (array_diff(array_keys($attributes), array_keys($seen))) {
            return false;
        }
        // Only literal zero/false occurs in the two owning policy expressions.
        $constant = '\{CONST :consttype (?<type>[0-9]+) :consttypmod -1 :constcollid 0 :constlen (?<length>[1248]) :constbyval true :constisnull false :location -?[0-9]+ :constvalue \k<length> \[ (?:0 ){8}\]\}';
        preg_match_all('/' . $constant . '/', $nodes, $constants, PREG_SET_ORDER);
        if (count($constants) !== count(array_filter($tags[1], static fn (string $tag): bool => $tag === 'CONST'))) {
            return false;
        }
        $lengths = [(string)$boolean => '1'];
        foreach (['smallint' => '2', 'integer' => '4', 'bigint' => '8'] as $type => $length) {
            if (!ctype_digit((string)($types[$type] ?? ''))) {
                return false;
            }
            $lengths[(string)$types[$type]] = $length;
        }
        foreach ($constants as $constantNode) {
            if (($lengths[$constantNode['type']] ?? null) !== $constantNode['length']) {
                return false;
            }
        }
        // Atoms contain no nested nodes; every operator must have exactly two
        // scalar arguments and its actual pg_operator/pg_proc identity.
        $atom = '\{(?:VAR|CONST) [^{}]+\}';
        $operator = '\{OPEXPR :opno (?<operator>[0-9]+) :opfuncid (?<function>[0-9]+) :opresulttype ' . preg_quote((string)$boolean, '/')
            . ' :opretset false :opcollid 0 :inputcollid 0 :args \((?<left>' . $atom . ') (?<right>' . $atom . ')\) :location -?[0-9]+\}';
        preg_match_all('/' . $operator . '/', $nodes, $ops, PREG_SET_ORDER);
        if (!$ops || count($ops) !== count(array_filter($tags[1], static fn (string $tag): bool => $tag === 'OPEXPR'))) {
            return false;
        }
        foreach ($ops as $op) {
            $binding = $operators[$op['operator']] ?? null;
            preg_match('/:(?:vartype|consttype) ([0-9]+)/', $op['left'], $left);
            preg_match('/:(?:vartype|consttype) ([0-9]+)/', $op['right'], $right);
            if (!is_array($binding) || (string)($binding['function_oid'] ?? '') !== $op['function']
                || (string)($binding['left_type'] ?? '') !== ($left[1] ?? null)
                || (string)($binding['right_type'] ?? '') !== ($right[1] ?? null)
                || !in_array($binding['name'] ?? null, ['=', '<>', '>', '>='], true)
                || (($left[1] === (string)$boolean || $right[1] === (string)$boolean)
                    && ($binding['name'] !== '=' || $left[1] !== (string)$boolean || $right[1] !== (string)$boolean))) {
                return false;
            }
        }
        preg_match_all('/\{NULLTEST :arg (' . $atom . ') :nulltesttype [01] :argisrow false :location -?[0-9]+\}/', $nodes, $nullTests);
        if (count($nullTests[0]) !== count(array_filter($tags[1], static fn (string $tag): bool => $tag === 'NULLTEST'))
            || array_filter($nullTests[1], static fn (string $atom): bool => !str_starts_with($atom, '{VAR '))) {
            return false;
        }
        preg_match_all('/\{BOOLEXPR :boolop ([a-z]+) :args /', $nodes, $junctions);
        if (count($junctions[0]) !== count(array_filter($tags[1], static fn (string $tag): bool => $tag === 'BOOLEXPR'))
            || array_diff($junctions[1], ['and', 'or', 'not'])) {
            return false;
        }
        preg_match_all('/\{BOOLEXPR :boolop not :args \((' . $atom . ')\) :location -?[0-9]+\}/', $nodes, $negations);
        if (count($negations[0]) !== count(array_filter($junctions[1], static fn (string $op): bool => $op === 'not'))) {
            return false;
        }
        foreach ($negations[1] as $negation) {
            if (!preg_match('/\A\{VAR :varno 1 :varattno ([0-9]+)/', $negation, $attribute)
                || !in_array((int)$attribute[1], $booleanAttributes, true)) {
                return false;
            }
        }
        return true;
    }

    private static function nativeObject(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) && !array_is_list($value) ? $value : null;
    }

    /** Recognize only the captured unnarrowed builtin varchar[] -> text[] relabel shape. */
    private static function binaryModeArrayCoercions(string $nodes, array $tags, mixed $identity): bool
    {
        $arrays = count(array_filter($tags, static fn (string $tag): bool => $tag === 'ARRAYCOERCEEXPR'));
        $elements = count(array_filter($tags, static fn (string $tag): bool => $tag === 'CASETESTEXPR'));
        preg_match_all('/\{RELABELTYPE :arg \{CONST\b/', $nodes, $literalRelabels);
        $literalCount = count($literalRelabels[0]);
        if ($arrays === 0 && $literalCount === 0) {
            return $elements === 0;
        }
        if (($arrays === 0 && $elements !== 0)
            || ($arrays !== 0 && ($arrays !== 1 || $elements !== 1 || $literalCount !== 0))) {
            return false;
        }
        if (count(array_filter($tags, static fn (string $tag): bool => in_array($tag, ['ARRAYEXPR', 'ARRAY'], true))) !== 1) {
            return false;
        }
        if (is_string($identity)) {
            $identity = json_decode($identity, true);
        }
        if (!is_array($identity) || !self::isTrue($identity['binary'] ?? null)) {
            return false;
        }
        foreach (['varchar', 'varchar_array', 'text', 'text_array'] as $type) {
            if (!is_string($identity[$type] ?? null) || !ctype_digit($identity[$type])) {
                return false;
            }
        }
        $varchar = preg_quote($identity['varchar'], '/');
        $varcharArray = preg_quote($identity['varchar_array'], '/');
        $text = preg_quote($identity['text'], '/');
        $textArray = preg_quote($identity['text_array'], '/');
        // All literal and result collations stay equal through the binary
        // coercion; the actual inherited collation is never replaced here.
        $constant = '\{CONST :consttype ' . $varchar . ' :consttypmod -1 :constcollid \k<coll> :constlen -1 '
            . ':constbyval false :constisnull false :location -?[0-9]+ :constvalue [1-9][0-9]* \[ (?:-?[0-9]+ )+\]\}';
        if ($arrays === 0) {
            // PostgreSQL reparses a CHECK after ALTER TYPE. Its equivalent
            // text[] ARRAYEXPR retains each original varchar CONST under an
            // explicit binary RELABELTYPE, rather than an ARRAYCOERCEEXPR.
            $literal = '\{RELABELTYPE :arg ' . $constant . ' :resulttype ' . $text
                . ' :resulttypmod -1 :resultcollid \k<coll> :relabelformat 1 :location -?[0-9]+\}';
            $rewritten = '\{(?:ARRAYEXPR|ARRAY) :array_typeid ' . $textArray
                . ' :array_collid (?<coll>[0-9]+) :element_typeid ' . $text
                . ' :elements \((?:' . $literal . '\s*)+\) :multidims false(?: :list_start -?[0-9]+ :list_end -?[0-9]+)? :location -?[0-9]+\}';
            if (preg_match_all('/' . $rewritten . '/', $nodes, $matches) !== 1) {
                return false;
            }
            preg_match_all('/\{RELABELTYPE :arg \{CONST\b/', $matches[0][0], $matchedLiterals);
            return count($matchedLiterals[0]) === $literalCount;
        }
        $pattern = '\{ARRAYCOERCEEXPR :arg \{(?:ARRAYEXPR|ARRAY) :array_typeid ' . $varcharArray
            . ' :array_collid (?<coll>[0-9]+) :element_typeid ' . $varchar . ' :elements \((?:' . $constant . '\s*)+\) '
            . ':multidims false(?: :list_start -?[0-9]+ :list_end -?[0-9]+)? :location -?[0-9]+\} :elemexpr \{RELABELTYPE :arg \{CASETESTEXPR :typeId '
            . $varchar . ' :typeMod -1 :collation 0\} :resulttype ' . $text . ' :resulttypmod -1 '
            . ':resultcollid \k<coll> :relabelformat 2 :location -?[0-9]+\} :resulttype ' . $textArray
            . ' :resulttypmod -1 :resultcollid \k<coll> :coerceformat 2 :location -?[0-9]+\}';
        return preg_match_all('/' . $pattern . '/', $nodes, $matches) === $arrays;
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
