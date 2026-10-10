<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Named inherited-selection CHECKs derive from actual association/mode metadata. */
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
                    $differences[] = 'Changed, missing or unenforced native inherited reference CHECK: ' . $table . '.' . $policy['constraint'];
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
