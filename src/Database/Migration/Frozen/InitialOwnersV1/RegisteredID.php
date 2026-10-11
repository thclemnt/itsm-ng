<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\Frozen\InitialOwnersV1;

/** Proposed initial table facts, pending application declaration qualification. */
final class RegisteredID extends InitialOwnerAdoption
{
    public const TABLE = 'glpi_registeredids';
    public const CHECK = 'glpi_registeredids_parent_kind';
    public const OWNERS = [
        'DeviceNetworkCard' => ['devicenetworkcards_id', 'glpi_devicenetworkcards', 'devicenetworkcards_id', 'glpi_registeredids_devicenetworkcards_id'],
        'DevicePci' => ['devicepcis_id', 'glpi_devicepcis', 'devicepcis_id', 'glpi_registeredids_devicepcis_id'],
        'DeviceControl' => ['devicecontrols_id', 'glpi_devicecontrols', 'devicecontrols_id', 'glpi_registeredids_devicecontrols_id'],
        'DeviceGraphicCard' => ['devicegraphiccards_id', 'glpi_devicegraphiccards', 'devicegraphiccards_id', 'glpi_registeredids_devicegraphiccards_id'],
        'DeviceSoundCard' => ['devicesoundcards_id', 'glpi_devicesoundcards', 'devicesoundcards_id', 'glpi_registeredids_devicesoundcards_id'],
        'Manufacturer' => ['manufacturers_id', 'glpi_manufacturers', 'manufacturers_id', 'glpi_registeredids_manufacturers_id'],
    ];
}
