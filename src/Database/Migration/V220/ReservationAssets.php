<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen 20261001 upgrade for the seven core reservable asset kinds. */
final class ReservationAssets extends TypedItemMigration
{
    protected function tables(): array
    {
        return ['glpi_reservationitems'];
    }

    protected static function targets(): array
    {
        return ['Computer' => 'computers', 'Monitor' => 'monitors', 'NetworkEquipment' => 'networkequipments',
            'Peripheral' => 'peripherals', 'Phone' => 'phones', 'Printer' => 'printers', 'Software' => 'softwares'];
    }
}
