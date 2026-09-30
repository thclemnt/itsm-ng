<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Nullable scope for global configuration; root entity zero stays distinct. */
final class GlobalEntityScopes
{
    public const RELATIONS = [
        'glpi_fieldunicities' => ['entities_id' => 'glpi_entities'],
        'glpi_savedsearches' => ['entities_id' => 'glpi_entities'],
    ];

    public static function normalizeLegacy(string $table, array $values): array
    {
        if (isset(self::RELATIONS[$table]) && array_key_exists('entities_id', $values) && ContentAudienceScopes::isUnrestricted($values['entities_id'])) {
            $values['entities_id'] = null;
        }
        return $values;
    }
}
