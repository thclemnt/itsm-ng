<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Custom plugin tables retain their installed query contract until the plugin supplies mappings. */
final class UnmappedDropdownChoices
{
    public static function read(\DBAdapter $database, string $table, array $where, array $order, array $translations, string $kind, string $language, int $limit, int $offset): \DBmysqlIterator
    {
        if (isset(EntityRegistry::tables()[$table])) {
            throw new \LogicException('Mapped core dropdown choices require their ORM repository.');
        }
        $criteria = ['SELECT' => [$table . '.*'], 'FROM' => $table, 'WHERE' => $where, 'ORDERBY' => $order, 'LIMIT' => $limit, 'START' => $offset];
        foreach ($translations as $role => $translation) {
            $criteria['SELECT'][] = $role . '.value AS ' . $translation['output'];
            $criteria['LEFT JOIN']['glpi_dropdowntranslations AS ' . $role] = [
                'ON' => [$role => 'items_id', $table => 'id', ['AND' => [
                    $role . '.itemtype' => $kind, $role . '.language' => $language, $role . '.field' => $translation['field'],
                ]]],
            ];
        }
        return $database->request($criteria);
    }
}
