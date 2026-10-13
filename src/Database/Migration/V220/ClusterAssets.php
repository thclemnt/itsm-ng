<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen 20261001 upgrade of configured cluster assets. */
final class ClusterAssets extends TypedItemMigration
{
    protected function tables(): array
    {
        return ['glpi_items_clusters'];
    }

    protected static function targets(): array
    {
        return [
            'Computer' => 'computers',
            'NetworkEquipment' => 'networkequipments',
        ];
    }
}
