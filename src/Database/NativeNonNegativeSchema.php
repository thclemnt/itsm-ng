<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** The finite single-integer CHECK shares the operation's existing native catalog. */
final class NativeNonNegativeSchema
{
    /** No native I/O: the caller shares this snapshot with its other CHECK inspectors. */
    public static function compare(array $policies, array $checks): array
    {
        $differences = [];
        foreach ($policies as $table => $columns) {
            foreach ($columns as $policy) {
                $check = $checks[$table][$policy['constraint']] ?? null;
                if ($check === null || !self::matches($policy, $check)) {
                    $differences[] = 'Changed, missing or unenforced native nonnegative CHECK: ' . $table . '.' . $policy['constraint'];
                }
            }
        }
        return $differences;
    }

    private static function matches(array $policy, array $check): bool
    {
        if (!self::isTrue($check['enforced'] ?? null) || !self::isTrue($check['validated'] ?? null)
            || !is_string($check['clause'] ?? null) || !is_string($check['native_nodes'] ?? null)) {
            return false;
        }
        $columns = self::nativeList($check['checked_columns'] ?? null);
        $operators = self::nativeList($check['integer_ge_oids'] ?? null);
        $nodes = $check['native_nodes'];
        if ($columns !== [$policy['column']] || $operators === null || strlen($nodes) > 8192) {
            return false;
        }
        // This is a finite native node shape, not a second SQL or pg_node_tree parser.
        // One scalar variable and literal feed exactly one builtin integer comparison.
        preg_match_all('/\{([A-Z][A-Z_0-9]*)\b/', $nodes, $tags);
        $shape = $tags[1];
        sort($shape);
        if ($shape !== ['CONST', 'OPEXPR', 'VAR']) {
            return false;
        }
        preg_match_all('/:opno ([0-9]+)(?=\s|})/', $nodes, $ids);
        if (count($ids[1]) !== 1 || !in_array($ids[1][0], array_map('strval', $operators), true)) {
            return false;
        }
        return SubjectPolicyExpression::equivalent(
            $policy['check'],
            $check['clause'],
            true,
            integerTypes: [$policy['column'] => $policy['type']],
        );
    }

    /** Native arrays are emitted as JSON by the shared catalog, never guessed from PG array syntax. */
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
