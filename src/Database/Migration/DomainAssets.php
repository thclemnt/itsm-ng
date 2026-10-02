<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen 20261001 upgrade of configured domain assets. */
final class DomainAssets extends TypedItemMigration
{
    public const VERSION = '20261001_domain_assets';

    protected function tables(): array
    {
        return ['glpi_domains_items'];
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
            'Appliance' => 'appliances',
            'Certificate' => 'certificates',
        ];
    }
}
