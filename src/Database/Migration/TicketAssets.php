<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen 20261001 upgrade for the twenty core ticket asset kinds. */
final class TicketAssets extends TypedItemMigration
{
    public const VERSION = '20261001_ticket_assets';

    protected function tables(): array
    {
        return ['glpi_items_tickets'];
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
