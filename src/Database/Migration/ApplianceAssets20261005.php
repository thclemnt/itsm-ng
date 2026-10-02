<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen 20261005 appliance relationship upgrade; historical targets never follow current metadata. */
final class ApplianceAssets20261005 extends StagedTypedItemMigration
{
    public const VERSION = '20261005_appliance_assets';

    protected function version(): string
    {
        return self::VERSION;
    }

    protected function table(): string
    {
        return 'glpi_appliances_items';
    }

    protected static function targets(): array
    {
        return [
            'Computer' => 'computers',
            'Monitor' => 'monitors',
            'NetworkEquipment' => 'networkequipments',
            'Peripheral' => 'peripherals',
            'Phone' => 'phones',
            'Printer' => 'printers',
            'Software' => 'softwares',
            'Cluster' => 'clusters',
        ];
    }
}
