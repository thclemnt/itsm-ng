<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Mapping\ReferenceKind;

/** Conversion at the legacy model boundary; Doctrine owns the canonical associations. */
final class ReferenceValues
{
    public static function isEmptySelection(mixed $value): bool
    {
        return in_array($value, [0, '0', '', false], true);
    }

    public static function isUnrestricted(mixed $value): bool
    {
        return is_numeric($value) && $value < 0;
    }

    public static function normalizeLegacy(string $table, array $values): array
    {
        foreach (EntityRegistry::references($table) as $column => $reference) {
            if (!array_key_exists($column, $values)) {
                continue;
            }
            $empty = match ($reference->policy->kind) {
                ReferenceKind::EmptySelection => self::isEmptySelection($values[$column]),
                ReferenceKind::Audience, ReferenceKind::GlobalScope => self::isUnrestricted($values[$column]),
                ReferenceKind::RootParent => $values[$column] === -1 || $values[$column] === '-1',
                default => false,
            };
            if ($empty) {
                $values[$column] = null;
            }
        }
        return EntityConfigurationReferences::normalizeLegacy($table, $values);
    }

    public static function legacyRow(string $table, array $row): array
    {
        foreach (EntityRegistry::references($table) as $column => $reference) {
            if ($reference->policy->kind === ReferenceKind::RootParent && array_key_exists($column, $row) && $row[$column] === null) {
                $row[$column] = -1;
            }
        }
        return $table === 'glpi_entities' ? EntityConfigurationReferences::legacyRow($row) : $row;
    }
}
