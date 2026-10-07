<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use InvalidArgumentException;
use itsmng\Database\Mapping\MappedReference;
use itsmng\Database\Mapping\ReferenceKind;

/** Audited inherited entity settings, including the real software root target. */
final class EntityConfigurationReferences
{
    /** @return array<string, MappedReference> */
    public static function fields(): array
    {
        return array_filter(
            EntityRegistry::references('glpi_entities'),
            static fn (MappedReference $reference): bool => $reference->policy->kind === ReferenceKind::Inherited
        );
    }

    public static function normalizeLegacy(string $table, array $values): array
    {
        if ($table !== 'glpi_entities') {
            return $values;
        }
        foreach (self::fields() as $column => $definition) {
            $modeColumn = $definition->policy->modeProperty;
            $hasValue = array_key_exists($column, $values);
            $hasMode = array_key_exists($modeColumn, $values);
            if (!$hasValue && !$hasMode) {
                continue;
            }
            $value = $hasValue ? $values[$column] : null;
            if ($value !== null && !filter_var($value, FILTER_VALIDATE_INT) && !in_array($value, [0, '0', '', false], true)) {
                throw new InvalidArgumentException('Entity reference requires an integer: ' . $column);
            }
            $value = $value === null ? null : (int)$value;
            $mode = $hasMode ? ($values[$modeColumn] instanceof ReferenceMode ? $values[$modeColumn] : ReferenceMode::from($values[$modeColumn]))
                : ($value === -2 ? ReferenceMode::Inherit : ($value === -10 && !$definition->policy->emptyZero ? ReferenceMode::Unchanged : ReferenceMode::Explicit));
            if (($value !== null && $value < 0 && $value !== -2 && !($value === -10 && !$definition->policy->emptyZero))
                || ($mode === ReferenceMode::Unchanged && $definition->policy->emptyZero)) {
                throw new InvalidArgumentException('Invalid entity reference policy: ' . $column);
            }
            if ($mode !== ReferenceMode::Explicit) {
                if ($hasValue && $value !== null && $value >= 0) {
                    throw new InvalidArgumentException('Inherited/unchanged policy cannot retain a selected reference: ' . $column);
                }
                $values[$column] = null;
            } elseif ($hasValue) {
                if ($value !== null && $value < 0) {
                    throw new InvalidArgumentException('Explicit entity reference cannot be negative: ' . $column);
                }
                $values[$column] = $definition->policy->emptyZero && $value === 0 ? null : $value;
            }
            $values[$modeColumn] = $mode->value;
        }
        return $values;
    }

    /** The existing forms and plugins still exchange sentinel dropdown values. */
    public static function legacyRow(array $row): array
    {
        foreach (self::fields() as $column => $definition) {
            if (!array_key_exists($column, $row) || !isset($row[$definition->policy->modeProperty])) {
                continue;
            }
            $mode = $row[$definition->policy->modeProperty];
            $mode = $mode instanceof ReferenceMode ? $mode : ReferenceMode::from($mode);
            $row[$column] = match ($mode) {
                ReferenceMode::Inherit => -2,
                ReferenceMode::Unchanged => -10,
                ReferenceMode::Explicit => $row[$column] ?? 0,
            };
        }
        return $row;
    }

    /** Canonical model updates must also update the logical field used by hooks/history. */
    public static function legacyInput(array $input, array $current = []): array
    {
        foreach (self::fields() as $column => $definition) {
            if (!array_key_exists($definition->policy->modeProperty, $input)) {
                continue;
            }
            $mode = $input[$definition->policy->modeProperty];
            $mode = $mode instanceof ReferenceMode ? $mode : ReferenceMode::from($mode);
            if (!array_key_exists($column, $input) && $mode === ReferenceMode::Explicit) {
                if (!$definition->policy->emptyZero && (!isset($current[$column]) || (int)$current[$column] < 0)) {
                    throw new InvalidArgumentException('Explicit software entity requires a target');
                }
                $input[$column] = max(0, (int)($current[$column] ?? 0));
            }
        }
        return self::legacyRow(self::normalizeLegacy('glpi_entities', $input));
    }

    public static function legacyChanges(string $table, array $columns): array
    {
        if ($table === 'glpi_entities') {
            foreach (self::fields() as $column => $definition) {
                if (in_array($definition->policy->modeProperty, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }
        return array_values(array_unique($columns));
    }

    /** Virtual legacy selection in mapped criteria; canonical queries use IDENTITY directly. */
    public static function selection(string $column, string $alias = 'r'): string
    {
        $definition = self::fields()[$column];
        return 'CASE WHEN ' . $alias . '.' . $definition->policy->modeProperty . " = 'inherit' THEN -2 WHEN "
            . $alias . '.' . $definition->policy->modeProperty . " = 'unchanged' THEN -10 ELSE COALESCE(IDENTITY("
            . $alias . '.' . $definition->association . '), 0) END';
    }
}
