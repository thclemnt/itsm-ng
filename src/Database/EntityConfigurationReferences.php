<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Audited inherited entity settings, including the real software root target. */
final class EntityConfigurationReferences
{
    public const FIELDS = [
        'authldaps_id' => ['target' => 'glpi_authldaps', 'association' => 'authldap', 'mode' => 'ldap_mode', 'default' => 'explicit', 'empty_zero' => true],
        'calendars_id' => ['target' => 'glpi_calendars', 'association' => 'calendar', 'mode' => 'calendar_mode', 'default' => 'inherit', 'empty_zero' => true],
        'tickettemplates_id' => ['target' => 'glpi_tickettemplates', 'association' => 'tickettemplate', 'mode' => 'tickettemplate_mode', 'default' => 'inherit', 'empty_zero' => true],
        'changetemplates_id' => ['target' => 'glpi_changetemplates', 'association' => 'changetemplate', 'mode' => 'changetemplate_mode', 'default' => 'inherit', 'empty_zero' => true],
        'problemtemplates_id' => ['target' => 'glpi_problemtemplates', 'association' => 'problemtemplate', 'mode' => 'problemtemplate_mode', 'default' => 'inherit', 'empty_zero' => true],
        'entities_id_software' => ['target' => 'glpi_entities', 'association' => 'software_entity', 'mode' => 'software_entity_mode', 'default' => 'inherit', 'empty_zero' => false],
    ];

    public const RELATIONS = ['glpi_entities' => [
        'authldaps_id' => 'glpi_authldaps', 'calendars_id' => 'glpi_calendars',
        'tickettemplates_id' => 'glpi_tickettemplates', 'changetemplates_id' => 'glpi_changetemplates',
        'problemtemplates_id' => 'glpi_problemtemplates', 'entities_id_software' => 'glpi_entities',
    ]];

    public static function normalizeLegacy(string $table, array $values): array
    {
        if ($table !== 'glpi_entities') {
            return $values;
        }
        foreach (self::FIELDS as $column => $definition) {
            $modeColumn = $definition['mode'];
            $hasValue = array_key_exists($column, $values);
            $hasMode = array_key_exists($modeColumn, $values);
            if (!$hasValue && !$hasMode) {
                continue;
            }
            $value = $hasValue ? $values[$column] : null;
            if ($value !== null && !filter_var($value, FILTER_VALIDATE_INT) && !in_array($value, [0, '0', '', false], true)) {
                throw new \InvalidArgumentException('Entity reference requires an integer: ' . $column);
            }
            $value = $value === null ? null : (int)$value;
            $mode = $hasMode ? ($values[$modeColumn] instanceof ReferenceMode ? $values[$modeColumn] : ReferenceMode::from($values[$modeColumn]))
                : ($value === -2 ? ReferenceMode::Inherit : ($value === -10 && !$definition['empty_zero'] ? ReferenceMode::Unchanged : ReferenceMode::Explicit));
            if (($value !== null && $value < 0 && $value !== -2 && !($value === -10 && !$definition['empty_zero']))
                || ($mode === ReferenceMode::Unchanged && $definition['empty_zero'])) {
                throw new \InvalidArgumentException('Invalid entity reference policy: ' . $column);
            }
            if ($mode !== ReferenceMode::Explicit) {
                if ($hasValue && $value !== null && $value >= 0) {
                    throw new \InvalidArgumentException('Inherited/unchanged policy cannot retain a selected reference: ' . $column);
                }
                $values[$column] = null;
            } elseif ($hasValue) {
                if ($value !== null && $value < 0) {
                    throw new \InvalidArgumentException('Explicit entity reference cannot be negative: ' . $column);
                }
                $values[$column] = $definition['empty_zero'] && $value === 0 ? null : $value;
            }
            $values[$modeColumn] = $mode->value;
        }
        return $values;
    }

    /** The existing forms and plugins still exchange sentinel dropdown values. */
    public static function legacyRow(array $row): array
    {
        foreach (self::FIELDS as $column => $definition) {
            if (!array_key_exists($column, $row) || !isset($row[$definition['mode']])) {
                continue;
            }
            $mode = $row[$definition['mode']];
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
        foreach (self::FIELDS as $column => $definition) {
            if (!array_key_exists($definition['mode'], $input)) {
                continue;
            }
            $mode = $input[$definition['mode']];
            $mode = $mode instanceof ReferenceMode ? $mode : ReferenceMode::from($mode);
            if (!array_key_exists($column, $input) && $mode === ReferenceMode::Explicit) {
                if (!$definition['empty_zero'] && (!isset($current[$column]) || (int)$current[$column] < 0)) {
                    throw new \InvalidArgumentException('Explicit software entity requires a target');
                }
                $input[$column] = max(0, (int)($current[$column] ?? 0));
            }
        }
        return self::legacyRow(self::normalizeLegacy('glpi_entities', $input));
    }

    public static function legacyChanges(string $table, array $columns): array
    {
        if ($table === 'glpi_entities') {
            foreach (self::FIELDS as $column => $definition) {
                if (in_array($definition['mode'], $columns, true)) {
                    $columns[] = $column;
                }
            }
        }
        return array_values(array_unique($columns));
    }

    /** Virtual legacy selection in mapped criteria; canonical queries use IDENTITY directly. */
    public static function selection(string $column, string $alias = 'r'): string
    {
        $definition = self::FIELDS[$column];
        return 'CASE WHEN ' . $alias . '.' . $definition['mode'] . " = 'inherit' THEN -2 WHEN "
            . $alias . '.' . $definition['mode'] . " = 'unchanged' THEN -10 ELSE COALESCE(IDENTITY("
            . $alias . '.' . $definition['association'] . '), 0) END';
    }
}
