<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Mapping\LegacyInput;

/** Copy canonical values; each entity owns conversion of the proposed override. */
final class CloneInput
{
    public static function merge(string $table, array $source, array $override): array
    {
        $class = EntityRegistry::tables()[$table] ?? null;
        if ($class === null) {
            return array_replace($source, $override);
        }
        $readOnly = array_fill_keys(EntityRegistry::readOnlyColumns($table), true);
        $copy = array_diff_key($source, $readOnly);
        $references = EntityRegistry::discriminatedReferences($table);
        $record = new $class();
        if ($record instanceof LegacyInput) {
            foreach ($references as $identity => $reference) {
                $discriminator = $reference['discriminator'];
                $fallback = $reference['fallback_column'] ?? null;
                $columns = array_column($reference['selections'], 'column');
                $keys = [$discriminator, $identity, ...$columns];
                if ($fallback !== null) {
                    $keys[] = $fallback;
                }
                $keySet = array_fill_keys($keys, true);
                if (!array_intersect_key($override, $keySet)) {
                    continue;
                }
                // Current kind/identity provide context, never the old owning columns.
                $proposal = array_intersect_key($override, $keySet);
                if (!array_key_exists($discriminator, $proposal)) {
                    $proposal[$discriminator] = $source[$discriminator] ?? null;
                }
                $kind = $proposal[$discriminator];
                $selection = is_string($kind) || is_int($kind)
                    ? ($reference['selections'][$kind] ?? null) : null;
                $selectedColumn = $selection['column'] ?? $fallback;
                if (!array_key_exists($identity, $proposal)
                    && ($selectedColumn === null || !array_key_exists($selectedColumn, $proposal))) {
                    $proposal[$identity] = ($kind === null || $kind === '') && $fallback === null
                        ? ($reference['empty_value'] ?? null) : ($source[$identity] ?? null);
                }
                $normalized = $record->normalizeInput($proposal);
                $override = array_replace(array_diff_key($override, $keySet), $normalized);
            }
        }
        $input = array_replace($copy, $override);
        // Legacy public hooks still consume logical identities. Recompute them from
        // the final owning columns, instead of retaining the source projection.
        foreach ($references as $identity => $reference) {
            if (!isset($readOnly[$identity])) {
                continue;
            }
            $kind = $input[$reference['discriminator']] ?? null;
            $selection = is_string($kind) || is_int($kind)
                ? ($reference['selections'][$kind] ?? null) : null;
            $fallback = $reference['fallback_column'] ?? null;
            $input[$identity] = $selection !== null
                ? ($input[$selection['column']] ?? $selection['empty_value'] ?? null)
                : ($fallback === null ? ($reference['empty_value'] ?? null) : ($input[$fallback] ?? null));
        }
        return $input;
    }
}
