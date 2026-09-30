<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** A NULL entity means an unrestricted audience; zero is the real root entity. */
final class ContentAudienceScopes
{
    public const RELATIONS = [
        'glpi_groups_knowbaseitems' => ['entities_id' => 'glpi_entities'],
        'glpi_knowbaseitems_profiles' => ['entities_id' => 'glpi_entities'],
        'glpi_groups_reminders' => ['entities_id' => 'glpi_entities'],
        'glpi_profiles_reminders' => ['entities_id' => 'glpi_entities'],
        'glpi_groups_rssfeeds' => ['entities_id' => 'glpi_entities'],
        'glpi_profiles_rssfeeds' => ['entities_id' => 'glpi_entities'],
    ];

    public static function isUnrestricted(mixed $value): bool
    {
        return is_numeric($value) && $value < 0;
    }

    public static function normalizeLegacy(string $table, array $values): array
    {
        if (isset(self::RELATIONS[$table]) && array_key_exists('entities_id', $values) && self::isUnrestricted($values['entities_id'])) {
            $values['entities_id'] = null;
        }
        return $values;
    }
}
