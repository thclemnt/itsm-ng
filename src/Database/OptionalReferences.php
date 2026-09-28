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

    public const STOCK = [
        'glpi_cartridges' => ['printers_id' => 'glpi_printers'],
        'glpi_cartridgeitems' => ['cartridgeitemtypes_id' => 'glpi_cartridgeitemtypes'],
        'glpi_consumableitems' => ['consumableitemtypes_id' => 'glpi_consumableitemtypes'],
    ];

    public const FINANCIAL = [
        'glpi_contracts' => ['contracttypes_id' => 'glpi_contracttypes'],
        'glpi_budgets' => ['budgettypes_id' => 'glpi_budgettypes'],
        'glpi_softwarelicenses' => ['softwarelicensetypes_id' => 'glpi_softwarelicensetypes'],
        'glpi_infocoms' => ['budgets_id' => 'glpi_budgets'],
        'glpi_contractcosts' => ['budgets_id' => 'glpi_budgets'],
        'glpi_ticketcosts' => ['budgets_id' => 'glpi_budgets'],
        'glpi_problemcosts' => ['budgets_id' => 'glpi_budgets'],
        'glpi_changecosts' => ['budgets_id' => 'glpi_budgets'],
        'glpi_projectcosts' => ['budgets_id' => 'glpi_budgets'],
    ];

    public const FINANCIAL_METADATA = [
        'glpi_suppliers' => ['suppliertypes_id' => 'glpi_suppliertypes'],
        'glpi_infocoms' => ['suppliers_id' => 'glpi_suppliers', 'businesscriticities_id' => 'glpi_businesscriticities'],
        'glpi_budgets' => ['locations_id' => 'glpi_locations'],
        'glpi_contracts' => ['states_id' => 'glpi_states'],
    ];

    public const MANUFACTURERS = [
        'glpi_appliances' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_cartridgeitems' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_certificates' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_computerantiviruses' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_computers' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_consumableitems' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicebatteries' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicecases' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicecontrols' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicedrives' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicefirmwares' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicegenerics' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicegraphiccards' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_deviceharddrives' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicememories' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicemotherboards' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicenetworkcards' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicepcis' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicepowersupplies' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_deviceprocessors' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicesensors' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicesimcards' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_devicesoundcards' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_enclosures' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_monitors' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_networkequipments' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_passivedcequipments' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_pdus' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_peripherals' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_phones' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_printers' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_racks' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_softwarelicenses' => ['manufacturers_id' => 'glpi_manufacturers'],
        'glpi_softwares' => ['manufacturers_id' => 'glpi_manufacturers'],
    ];

    public const RELATIONS = [
        ...self::MODELS,
        ...self::PROJECT_HIERARCHY,
        ...self::INFRASTRUCTURE,
        ...self::ASSET_CLASSIFICATION,
        ...self::STOCK,
        ...self::FINANCIAL,
        ...self::FINANCIAL_METADATA,
        ...self::MANUFACTURERS,
        'glpi_appliances' => [...self::INFRASTRUCTURE['glpi_appliances'], ...self::MANUFACTURERS['glpi_appliances']],
        'glpi_budgets' => [...self::FINANCIAL['glpi_budgets'], ...self::FINANCIAL_METADATA['glpi_budgets']],
        'glpi_cartridgeitems' => [...self::STOCK['glpi_cartridgeitems'], ...self::MANUFACTURERS['glpi_cartridgeitems']],
        'glpi_certificates' => [...self::INFRASTRUCTURE['glpi_certificates'], ...self::MANUFACTURERS['glpi_certificates']],
        'glpi_computers' => [...self::ASSET_CLASSIFICATION['glpi_computers'], ...self::MANUFACTURERS['glpi_computers']],
        'glpi_consumableitems' => [...self::STOCK['glpi_consumableitems'], ...self::MANUFACTURERS['glpi_consumableitems']],
        'glpi_contracts' => [...self::FINANCIAL['glpi_contracts'], ...self::FINANCIAL_METADATA['glpi_contracts']],
        'glpi_devicebatteries' => [...self::MODELS['glpi_devicebatteries'], ...self::MANUFACTURERS['glpi_devicebatteries']],
        'glpi_devicecases' => [...self::MODELS['glpi_devicecases'], ...self::MANUFACTURERS['glpi_devicecases']],
        'glpi_devicecontrols' => [...self::MODELS['glpi_devicecontrols'], ...self::MANUFACTURERS['glpi_devicecontrols']],
        'glpi_devicedrives' => [...self::MODELS['glpi_devicedrives'], ...self::MANUFACTURERS['glpi_devicedrives']],
        'glpi_devicefirmwares' => [...self::MODELS['glpi_devicefirmwares'], ...self::MANUFACTURERS['glpi_devicefirmwares']],
        'glpi_devicegenerics' => [...self::MODELS['glpi_devicegenerics'], ...self::MANUFACTURERS['glpi_devicegenerics']],
        'glpi_devicegraphiccards' => [...self::MODELS['glpi_devicegraphiccards'], ...self::MANUFACTURERS['glpi_devicegraphiccards']],
        'glpi_deviceharddrives' => [...self::MODELS['glpi_deviceharddrives'], ...self::MANUFACTURERS['glpi_deviceharddrives']],
        'glpi_devicememories' => [...self::MODELS['glpi_devicememories'], ...self::MANUFACTURERS['glpi_devicememories']],
        'glpi_devicemotherboards' => [...self::MODELS['glpi_devicemotherboards'], ...self::MANUFACTURERS['glpi_devicemotherboards']],
        'glpi_devicenetworkcards' => [...self::MODELS['glpi_devicenetworkcards'], ...self::MANUFACTURERS['glpi_devicenetworkcards']],
        'glpi_devicepcis' => [...self::MODELS['glpi_devicepcis'], ...self::MANUFACTURERS['glpi_devicepcis']],
        'glpi_devicepowersupplies' => [...self::MODELS['glpi_devicepowersupplies'], ...self::MANUFACTURERS['glpi_devicepowersupplies']],
        'glpi_deviceprocessors' => [...self::MODELS['glpi_deviceprocessors'], ...self::MANUFACTURERS['glpi_deviceprocessors']],
        'glpi_devicesoundcards' => [...self::MODELS['glpi_devicesoundcards'], ...self::MANUFACTURERS['glpi_devicesoundcards']],
        'glpi_enclosures' => [...self::MODELS['glpi_enclosures'], ...self::MANUFACTURERS['glpi_enclosures']],
        'glpi_infocoms' => [...self::FINANCIAL['glpi_infocoms'], ...self::FINANCIAL_METADATA['glpi_infocoms']],
        'glpi_monitors' => [...self::ASSET_CLASSIFICATION['glpi_monitors'], ...self::MANUFACTURERS['glpi_monitors']],
        'glpi_networkequipments' => [...self::ASSET_CLASSIFICATION['glpi_networkequipments'], ...self::MANUFACTURERS['glpi_networkequipments']],
        'glpi_passivedcequipments' => [...self::MODELS['glpi_passivedcequipments'], ...self::MANUFACTURERS['glpi_passivedcequipments']],
        'glpi_pdus' => [...self::MODELS['glpi_pdus'], ...self::INFRASTRUCTURE['glpi_pdus'], ...self::MANUFACTURERS['glpi_pdus']],
        'glpi_peripherals' => [...self::ASSET_CLASSIFICATION['glpi_peripherals'], ...self::MANUFACTURERS['glpi_peripherals']],
        'glpi_phones' => [...self::ASSET_CLASSIFICATION['glpi_phones'], ...self::MANUFACTURERS['glpi_phones']],
        'glpi_printers' => [...self::ASSET_CLASSIFICATION['glpi_printers'], ...self::MANUFACTURERS['glpi_printers']],
        'glpi_racks' => [...self::MODELS['glpi_racks'], ...self::INFRASTRUCTURE['glpi_racks'], ...self::MANUFACTURERS['glpi_racks']],
        'glpi_softwarelicenses' => [...self::FINANCIAL['glpi_softwarelicenses'], ...self::MANUFACTURERS['glpi_softwarelicenses']],
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
