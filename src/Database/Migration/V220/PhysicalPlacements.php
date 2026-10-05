<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen 20261001 upgrade for core rack and enclosure assets. */
final class PhysicalPlacements extends TypedItemMigration
{
    protected function tables(): array
    {
        return ['glpi_items_racks', 'glpi_items_enclosures'];
    }

    protected static function targets(): array
    {
        return ['Computer' => 'computers', 'Monitor' => 'monitors', 'NetworkEquipment' => 'networkequipments',
            'Peripheral' => 'peripherals', 'Enclosure' => 'enclosures', 'PDU' => 'pdus', 'PassiveDCEquipment' => 'passivedcequipments'];
    }

    protected static function column(string $target): string
    {
        return 'asset_' . $target . '_id';
    }
}
