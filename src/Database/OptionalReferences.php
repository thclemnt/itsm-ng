<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Audited optional references whose legacy dropdown value zero means no selection. */
final class OptionalReferences
{
    public const RELATIONS = [
        'glpi_devicebatteries' => ['devicebatterymodels_id' => 'glpi_devicebatterymodels'],
        'glpi_devicecases' => ['devicecasemodels_id' => 'glpi_devicecasemodels'],
        'glpi_devicecontrols' => ['devicecontrolmodels_id' => 'glpi_devicecontrolmodels'],
        'glpi_devicedrives' => ['devicedrivemodels_id' => 'glpi_devicedrivemodels'],
        'glpi_devicefirmwares' => ['devicefirmwaremodels_id' => 'glpi_devicefirmwaremodels'],
        'glpi_devicegenerics' => ['devicegenericmodels_id' => 'glpi_devicegenericmodels'],
        'glpi_devicegraphiccards' => ['devicegraphiccardmodels_id' => 'glpi_devicegraphiccardmodels'],
        'glpi_deviceharddrives' => ['deviceharddrivemodels_id' => 'glpi_deviceharddrivemodels'],
        'glpi_devicememories' => ['devicememorymodels_id' => 'glpi_devicememorymodels'],
        'glpi_devicemotherboards' => ['devicemotherboardmodels_id' => 'glpi_devicemotherboardmodels'],
        'glpi_devicenetworkcards' => ['devicenetworkcardmodels_id' => 'glpi_devicenetworkcardmodels'],
        'glpi_devicepcis' => ['devicepcimodels_id' => 'glpi_devicepcimodels'],
        'glpi_devicepowersupplies' => ['devicepowersupplymodels_id' => 'glpi_devicepowersupplymodels'],
        'glpi_deviceprocessors' => ['deviceprocessormodels_id' => 'glpi_deviceprocessormodels'],
        'glpi_devicesoundcards' => ['devicesoundcardmodels_id' => 'glpi_devicesoundcardmodels'],
        'glpi_enclosures' => ['enclosuremodels_id' => 'glpi_enclosuremodels'],
        'glpi_passivedcequipments' => ['passivedcequipmentmodels_id' => 'glpi_passivedcequipmentmodels'],
        'glpi_pdus' => ['pdumodels_id' => 'glpi_pdumodels'],
        'glpi_racks' => ['rackmodels_id' => 'glpi_rackmodels'],
    ];

    public static function isEmptySelection(mixed $value): bool
    {
        return in_array($value, [0, '0', '', false], true);
    }

    public static function normalizeLegacy(string $table, array $values): array
    {
        foreach (self::RELATIONS[$table] ?? [] as $column => $target) {
            if (array_key_exists($column, $values) && self::isEmptySelection($values[$column])) {
                $values[$column] = null;
            }
        }
        return $values;
    }
}
