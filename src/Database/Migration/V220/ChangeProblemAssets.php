<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen 20261001 upgrade for the core change and problem asset kinds. */
final class ChangeProblemAssets extends TypedItemMigration
{
    protected function tables(): array
    {
        return ['glpi_changes_items', 'glpi_items_problems'];
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
            'SoftwareLicense' => 'softwarelicenses',
            'Certificate' => 'certificates',
            'Line' => 'lines',
            'DCRoom' => 'dcrooms',
            'Rack' => 'racks',
            'Enclosure' => 'enclosures',
            'Cluster' => 'clusters',
            'PDU' => 'pdus',
            'Domain' => 'domains',
            'DomainRecord' => 'domainrecords',
            'Appliance' => 'appliances',
            'Item_DeviceSimcard' => 'items_devicesimcards',
            'PassiveDCEquipment' => 'passivedcequipments',
        ];
    }
}
