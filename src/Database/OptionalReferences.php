<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Audited optional references whose legacy dropdown value zero means no selection. */
final class OptionalReferences
{
    public const MODELS = [
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

    public const PROJECT_HIERARCHY = [
        'glpi_projects' => ['projects_id' => 'glpi_projects'],
        'glpi_projecttasks' => ['projects_id' => 'glpi_projects', 'projecttasks_id' => 'glpi_projecttasks'],
    ];

    public const INFRASTRUCTURE = [
        'glpi_appliances' => ['appliancetypes_id' => 'glpi_appliancetypes', 'applianceenvironments_id' => 'glpi_applianceenvironments'],
        'glpi_certificates' => ['certificatetypes_id' => 'glpi_certificatetypes'],
        'glpi_clusters' => ['clustertypes_id' => 'glpi_clustertypes'],
        'glpi_domains' => ['domaintypes_id' => 'glpi_domaintypes'],
        'glpi_domainrecords' => ['domainrecordtypes_id' => 'glpi_domainrecordtypes'],
        'glpi_domains_items' => ['domainrelations_id' => 'glpi_domainrelations'],
        'glpi_racks' => ['racktypes_id' => 'glpi_racktypes', 'dcrooms_id' => 'glpi_dcrooms'],
        'glpi_dcrooms' => ['datacenters_id' => 'glpi_datacenters'],
        'glpi_pdus' => ['pdutypes_id' => 'glpi_pdutypes'],
    ];

    public const ASSET_CLASSIFICATION = [
        'glpi_computers' => ['computermodels_id' => 'glpi_computermodels', 'computertypes_id' => 'glpi_computertypes'],
        'glpi_monitors' => ['monitormodels_id' => 'glpi_monitormodels', 'monitortypes_id' => 'glpi_monitortypes'],
        'glpi_printers' => ['printermodels_id' => 'glpi_printermodels', 'printertypes_id' => 'glpi_printertypes'],
        'glpi_phones' => ['phonemodels_id' => 'glpi_phonemodels', 'phonetypes_id' => 'glpi_phonetypes'],
        'glpi_peripherals' => ['peripheralmodels_id' => 'glpi_peripheralmodels', 'peripheraltypes_id' => 'glpi_peripheraltypes'],
        'glpi_networkequipments' => ['networkequipmentmodels_id' => 'glpi_networkequipmentmodels', 'networkequipmenttypes_id' => 'glpi_networkequipmenttypes'],
    ];

    public const RELATIONS = [
        ...self::MODELS, ...self::PROJECT_HIERARCHY, ...self::INFRASTRUCTURE, ...self::ASSET_CLASSIFICATION,
        'glpi_racks' => [...self::MODELS['glpi_racks'], ...self::INFRASTRUCTURE['glpi_racks']],
        'glpi_pdus' => [...self::MODELS['glpi_pdus'], ...self::INFRASTRUCTURE['glpi_pdus']],
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
