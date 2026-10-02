<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen 20261001 upgrade of configured cluster assets. */
final class ClusterAssets extends TypedItemMigration
{
    public const VERSION = '20261001_cluster_assets';

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
