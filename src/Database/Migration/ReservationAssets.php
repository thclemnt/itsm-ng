<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen 20261001 upgrade for the seven core reservable asset kinds. */
final class ReservationAssets extends TypedItemMigration
{
    public const VERSION = '20261001_reservation_assets';

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
