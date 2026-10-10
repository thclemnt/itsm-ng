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
            if (!$tags[1] || array_diff($tags[1], ['BOOLEXPR', 'SCALARARRAYOPEXPR', 'OPEXPR', 'NULLTEST', 'VAR', 'CONST', 'ARRAYEXPR', 'RELABELTYPE'])) {
                return false;
            }
            preg_match_all('/:opno ([0-9]+)(?=\s|})/', $nodes, $ids);
            if (!$ids[1] || array_diff($ids[1], array_map('strval', $operators))) {
                return false;
            }
        }
        return SubjectPolicyExpression::equivalent($policy['check'], $check['clause'], $postgres, $snapshot['ansi_quotes'],
            integerTypes: $policy['integer_types'], stringSelections: $policy['string_selections']);
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
