<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen 20261001 upgrade of the supported contract asset and component kinds. */
final class ContractAssets extends TypedItemMigration
{
    public const VERSION = '20261001_contract_assets';

    protected function tables(): array
    {
        return ['glpi_contracts_items'];
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
            'Appliance' => 'appliances',
            'Project' => 'projects',
            'Item_DeviceMotherboard' => 'items_devicemotherboards',
            'Item_DeviceFirmware' => 'items_devicefirmwares',
            'Item_DeviceProcessor' => 'items_deviceprocessors',
            'Item_DeviceMemory' => 'items_devicememories',
            'Item_DeviceHardDrive' => 'items_deviceharddrives',
            'Item_DeviceNetworkCard' => 'items_devicenetworkcards',
            'Item_DeviceDrive' => 'items_devicedrives',
            'Item_DeviceBattery' => 'items_devicebatteries',
            'Item_DeviceGraphicCard' => 'items_devicegraphiccards',
            'Item_DeviceSoundCard' => 'items_devicesoundcards',
            'Item_DeviceControl' => 'items_devicecontrols',
            'Item_DevicePci' => 'items_devicepcis',
            'Item_DeviceCase' => 'items_devicecases',
            'Item_DevicePowerSupply' => 'items_devicepowersupplies',
            'Item_DeviceGeneric' => 'items_devicegenerics',
            'Item_DeviceSimcard' => 'items_devicesimcards',
            'Item_DeviceSensor' => 'items_devicesensors',
        ];
    }
}
