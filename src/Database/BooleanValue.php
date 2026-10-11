<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use InvalidArgumentException;

/** A mapped flag is a boolean value, not an arbitrary truthy legacy scalar. */
final class BooleanValue
{
    public static function normalize(mixed $value, bool $nullable, string $field): ?bool
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (in_array($value, [false, 0, '0'], true)) {
            return false;
        }
        if (in_array($value, [true, 1, '1'], true)) {
            return true;
        }
        throw new InvalidArgumentException('Invalid boolean value: ' . $field . '. Expected zero or one'
            . ($nullable ? ', or NULL' : '') . '; received ' . get_debug_type($value) . '.');
    }

    /** Preserve absent keys and decode the public application's escaping exactly once. */
    public static function normalizeLegacyInput(string $table, array $input): array
    {
        foreach (EntityRegistry::booleanFields($table) as $column => $nullable) {
            if (array_key_exists($column, $input)) {
                $value = self::normalize(LegacyValues::decode($input[$column]), $nullable, $table . '.' . $column);
                // Callbacks and audit history retain the adapter's zero/one
                // representation; ORM assignment owns native boolean values.
                $input[$column] = $value === null ? null : (int)$value;
            }
        }
        return $input;
    }
}
