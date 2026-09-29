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

    public const LEGACY_COMPONENT_MODELS = [
        'glpi_devicepcis' => ['devicenetworkcardmodels_id' => 'glpi_devicenetworkcardmodels'],
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

    public const STATES = [
        'glpi_appliances' => ['states_id' => 'glpi_states'],
        'glpi_certificates' => ['states_id' => 'glpi_states'],
        'glpi_clusters' => ['states_id' => 'glpi_states'],
        'glpi_computers' => ['states_id' => 'glpi_states'],
        'glpi_devicegenerics' => ['states_id' => 'glpi_states'],
        'glpi_devicesensors' => ['states_id' => 'glpi_states'],
        'glpi_enclosures' => ['states_id' => 'glpi_states'],
        'glpi_items_devicebatteries' => ['states_id' => 'glpi_states'],
        'glpi_items_devicecases' => ['states_id' => 'glpi_states'],
        'glpi_items_devicecontrols' => ['states_id' => 'glpi_states'],
        'glpi_items_devicedrives' => ['states_id' => 'glpi_states'],
        'glpi_items_devicefirmwares' => ['states_id' => 'glpi_states'],
        'glpi_items_devicegenerics' => ['states_id' => 'glpi_states'],
        'glpi_items_devicegraphiccards' => ['states_id' => 'glpi_states'],
        'glpi_items_deviceharddrives' => ['states_id' => 'glpi_states'],
        'glpi_items_devicememories' => ['states_id' => 'glpi_states'],
        'glpi_items_devicemotherboards' => ['states_id' => 'glpi_states'],
        'glpi_items_devicenetworkcards' => ['states_id' => 'glpi_states'],
        'glpi_items_devicepcis' => ['states_id' => 'glpi_states'],
        'glpi_items_devicepowersupplies' => ['states_id' => 'glpi_states'],
        'glpi_items_deviceprocessors' => ['states_id' => 'glpi_states'],
        'glpi_items_devicesensors' => ['states_id' => 'glpi_states'],
        'glpi_items_devicesimcards' => ['states_id' => 'glpi_states'],
        'glpi_items_devicesoundcards' => ['states_id' => 'glpi_states'],
        'glpi_lines' => ['states_id' => 'glpi_states'],
        'glpi_monitors' => ['states_id' => 'glpi_states'],
        'glpi_networkequipments' => ['states_id' => 'glpi_states'],
        'glpi_passivedcequipments' => ['states_id' => 'glpi_states'],
        'glpi_pdus' => ['states_id' => 'glpi_states'],
        'glpi_peripherals' => ['states_id' => 'glpi_states'],
        'glpi_phones' => ['states_id' => 'glpi_states'],
        'glpi_printers' => ['states_id' => 'glpi_states'],
        'glpi_racks' => ['states_id' => 'glpi_states'],
        'glpi_softwarelicenses' => ['states_id' => 'glpi_states'],
        'glpi_softwareversions' => ['states_id' => 'glpi_states'],
    ];

    public const LOCATIONS = [
        'glpi_appliances' => ['locations_id' => 'glpi_locations'],
        'glpi_cartridgeitems' => ['locations_id' => 'glpi_locations'],
        'glpi_certificates' => ['locations_id' => 'glpi_locations'],
        'glpi_computers' => ['locations_id' => 'glpi_locations'],
        'glpi_consumableitems' => ['locations_id' => 'glpi_locations'],
        'glpi_datacenters' => ['locations_id' => 'glpi_locations'],
        'glpi_dcrooms' => ['locations_id' => 'glpi_locations'],
        'glpi_devicegenerics' => ['locations_id' => 'glpi_locations'],
        'glpi_devicesensors' => ['locations_id' => 'glpi_locations'],
        'glpi_enclosures' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicebatteries' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicecases' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicecontrols' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicedrives' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicefirmwares' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicegenerics' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicegraphiccards' => ['locations_id' => 'glpi_locations'],
        'glpi_items_deviceharddrives' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicememories' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicemotherboards' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicenetworkcards' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicepcis' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicepowersupplies' => ['locations_id' => 'glpi_locations'],
        'glpi_items_deviceprocessors' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicesensors' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicesimcards' => ['locations_id' => 'glpi_locations'],
        'glpi_items_devicesoundcards' => ['locations_id' => 'glpi_locations'],
        'glpi_lines' => ['locations_id' => 'glpi_locations'],
        'glpi_monitors' => ['locations_id' => 'glpi_locations'],
        'glpi_netpoints' => ['locations_id' => 'glpi_locations'],
        'glpi_networkequipments' => ['locations_id' => 'glpi_locations'],
        'glpi_passivedcequipments' => ['locations_id' => 'glpi_locations'],
        'glpi_pdus' => ['locations_id' => 'glpi_locations'],
        'glpi_peripherals' => ['locations_id' => 'glpi_locations'],
        'glpi_phones' => ['locations_id' => 'glpi_locations'],
        'glpi_printers' => ['locations_id' => 'glpi_locations'],
        'glpi_queuedchats' => ['locations_id' => 'glpi_locations'],
        'glpi_racks' => ['locations_id' => 'glpi_locations'],
        'glpi_softwarelicenses' => ['locations_id' => 'glpi_locations'],
        'glpi_softwares' => ['locations_id' => 'glpi_locations'],
        'glpi_tickets' => ['locations_id' => 'glpi_locations'],
        'glpi_users' => ['locations_id' => 'glpi_locations'],
    ];

    public const GROUPS = [
        'glpi_appliances' => ['groups_id' => 'glpi_groups', 'groups_id_tech' => 'glpi_groups'],
        'glpi_cartridgeitems' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_certificates' => ['groups_id' => 'glpi_groups', 'groups_id_tech' => 'glpi_groups'],
        'glpi_changetasks' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_clusters' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_computers' => ['groups_id' => 'glpi_groups', 'groups_id_tech' => 'glpi_groups'],
        'glpi_consumableitems' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_domainrecords' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_domains' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_enclosures' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_groups' => ['groups_id' => 'glpi_groups'],
        'glpi_items_devicesimcards' => ['groups_id' => 'glpi_groups'],
        'glpi_itilcategories' => ['groups_id' => 'glpi_groups'],
        'glpi_lines' => ['groups_id' => 'glpi_groups'],
        'glpi_monitors' => ['groups_id' => 'glpi_groups', 'groups_id_tech' => 'glpi_groups'],
        'glpi_networkequipments' => ['groups_id' => 'glpi_groups', 'groups_id_tech' => 'glpi_groups'],
        'glpi_passivedcequipments' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_pdus' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_peripherals' => ['groups_id' => 'glpi_groups', 'groups_id_tech' => 'glpi_groups'],
        'glpi_phones' => ['groups_id' => 'glpi_groups', 'groups_id_tech' => 'glpi_groups'],
        'glpi_planningexternalevents' => ['groups_id' => 'glpi_groups'],
        'glpi_printers' => ['groups_id' => 'glpi_groups', 'groups_id_tech' => 'glpi_groups'],
        'glpi_problemtasks' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_projects' => ['groups_id' => 'glpi_groups'],
        'glpi_queuedchats' => ['groups_id' => 'glpi_groups'],
        'glpi_racks' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_softwarelicenses' => ['groups_id' => 'glpi_groups', 'groups_id_tech' => 'glpi_groups'],
        'glpi_softwares' => ['groups_id' => 'glpi_groups', 'groups_id_tech' => 'glpi_groups'],
        'glpi_tasktemplates' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_tickettasks' => ['groups_id_tech' => 'glpi_groups'],
        'glpi_users' => ['groups_id' => 'glpi_groups'],
    ];

    public const INVENTORY_METADATA = [
        'glpi_clusters' => ['autoupdatesystems_id' => 'glpi_autoupdatesystems'],
        'glpi_computers' => ['autoupdatesystems_id' => 'glpi_autoupdatesystems', 'networks_id' => 'glpi_networks'],
        'glpi_computervirtualmachines' => ['virtualmachinestates_id' => 'glpi_virtualmachinestates', 'virtualmachinesystems_id' => 'glpi_virtualmachinesystems', 'virtualmachinetypes_id' => 'glpi_virtualmachinetypes'],
        'glpi_devicebatteries' => ['devicebatterytypes_id' => 'glpi_devicebatterytypes'],
        'glpi_devicecases' => ['devicecasetypes_id' => 'glpi_devicecasetypes'],
        'glpi_devicecontrols' => ['interfacetypes_id' => 'glpi_interfacetypes'],
        'glpi_devicedrives' => ['interfacetypes_id' => 'glpi_interfacetypes'],
        'glpi_devicefirmwares' => ['devicefirmwaretypes_id' => 'glpi_devicefirmwaretypes'],
        'glpi_devicegenerics' => ['devicegenerictypes_id' => 'glpi_devicegenerictypes'],
        'glpi_devicegraphiccards' => ['interfacetypes_id' => 'glpi_interfacetypes'],
        'glpi_deviceharddrives' => ['interfacetypes_id' => 'glpi_interfacetypes'],
        'glpi_devicememories' => ['devicememorytypes_id' => 'glpi_devicememorytypes'],
        'glpi_devicesensors' => ['devicesensormodels_id' => 'glpi_devicesensormodels', 'devicesensortypes_id' => 'glpi_devicesensortypes'],
        'glpi_devicesimcards' => ['devicesimcardtypes_id' => 'glpi_devicesimcardtypes'],
        'glpi_items_disks' => ['filesystems_id' => 'glpi_filesystems'],
        'glpi_items_operatingsystems' => ['operatingsystemarchitectures_id' => 'glpi_operatingsystemarchitectures', 'operatingsystemeditions_id' => 'glpi_operatingsystemeditions', 'operatingsystemkernelversions_id' => 'glpi_operatingsystemkernelversions', 'operatingsystems_id' => 'glpi_operatingsystems', 'operatingsystemservicepacks_id' => 'glpi_operatingsystemservicepacks', 'operatingsystemversions_id' => 'glpi_operatingsystemversions'],
        'glpi_networkequipments' => ['networks_id' => 'glpi_networks'],
        'glpi_operatingsystemkernelversions' => ['operatingsystemkernels_id' => 'glpi_operatingsystemkernels'],
        'glpi_passivedcequipments' => ['passivedcequipmenttypes_id' => 'glpi_passivedcequipmenttypes'],
        'glpi_phones' => ['phonepowersupplies_id' => 'glpi_phonepowersupplies'],
        'glpi_printers' => ['networks_id' => 'glpi_networks'],
        'glpi_softwareversions' => ['operatingsystems_id' => 'glpi_operatingsystems'],
    ];

    public const PLANNING_METADATA = [
        'glpi_planningexternalevents' => ['planningeventcategories_id' => 'glpi_planningeventcategories', 'planningexternaleventtemplates_id' => 'glpi_planningexternaleventtemplates'],
        'glpi_planningexternaleventtemplates' => ['planningeventcategories_id' => 'glpi_planningeventcategories'],
        'glpi_projects' => ['projectstates_id' => 'glpi_projectstates', 'projecttypes_id' => 'glpi_projecttypes'],
        'glpi_projecttasks' => ['projectstates_id' => 'glpi_projectstates', 'projecttasktypes_id' => 'glpi_projecttasktypes', 'projecttasktemplates_id' => 'glpi_projecttasktemplates'],
        'glpi_projecttasktemplates' => ['projects_id' => 'glpi_projects', 'projecttasks_id' => 'glpi_projecttasks', 'projectstates_id' => 'glpi_projectstates', 'projecttasktypes_id' => 'glpi_projecttasktypes'],
    ];

    public const ITIL_CLASSIFICATION = [
        'glpi_changes' => ['itilcategories_id' => 'glpi_itilcategories'],
        'glpi_changetasks' => ['taskcategories_id' => 'glpi_taskcategories', 'tasktemplates_id' => 'glpi_tasktemplates'],
        'glpi_itilcategories' => ['tickettemplates_id_incident' => 'glpi_tickettemplates', 'tickettemplates_id_demand' => 'glpi_tickettemplates', 'changetemplates_id' => 'glpi_changetemplates', 'problemtemplates_id' => 'glpi_problemtemplates', 'knowbaseitemcategories_id' => 'glpi_knowbaseitemcategories'],
        'glpi_itilfollowups' => ['requesttypes_id' => 'glpi_requesttypes'],
        'glpi_itilfollowuptemplates' => ['requesttypes_id' => 'glpi_requesttypes'],
        'glpi_itilsolutions' => ['solutiontypes_id' => 'glpi_solutiontypes'],
        'glpi_problems' => ['itilcategories_id' => 'glpi_itilcategories'],
        'glpi_problemtasks' => ['taskcategories_id' => 'glpi_taskcategories', 'tasktemplates_id' => 'glpi_tasktemplates'],
        'glpi_queuedchats' => ['itilcategories_id' => 'glpi_itilcategories'],
        'glpi_solutiontemplates' => ['solutiontypes_id' => 'glpi_solutiontypes'],
        'glpi_taskcategories' => ['knowbaseitemcategories_id' => 'glpi_knowbaseitemcategories'],
        'glpi_tasktemplates' => ['taskcategories_id' => 'glpi_taskcategories'],
        'glpi_tickets' => ['itilcategories_id' => 'glpi_itilcategories', 'requesttypes_id' => 'glpi_requesttypes'],
        'glpi_tickettasks' => ['taskcategories_id' => 'glpi_taskcategories', 'tasktemplates_id' => 'glpi_tasktemplates'],
        'glpi_users' => ['default_requesttypes_id' => 'glpi_requesttypes'],
    ];

    public const TREE_PARENTS = [
        'glpi_businesscriticities' => ['businesscriticities_id' => 'glpi_businesscriticities'],
        'glpi_documentcategories' => ['documentcategories_id' => 'glpi_documentcategories'],
        'glpi_itilcategories' => ['itilcategories_id' => 'glpi_itilcategories'],
        'glpi_knowbaseitemcategories' => ['knowbaseitemcategories_id' => 'glpi_knowbaseitemcategories'],
        'glpi_locations' => ['locations_id' => 'glpi_locations'],
        'glpi_softwarecategories' => ['softwarecategories_id' => 'glpi_softwarecategories'],
        'glpi_softwarelicensetypes' => ['softwarelicensetypes_id' => 'glpi_softwarelicensetypes'],
        'glpi_states' => ['states_id' => 'glpi_states'],
        'glpi_taskcategories' => ['taskcategories_id' => 'glpi_taskcategories'],
    ];

    public const ASSET_USERS = [
        'glpi_appliances' => ['users_id' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
        'glpi_cartridgeitems' => ['users_id_tech' => 'glpi_users'],
        'glpi_certificates' => ['users_id' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
        'glpi_clusters' => ['users_id_tech' => 'glpi_users'],
        'glpi_computers' => ['users_id' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
        'glpi_consumableitems' => ['users_id_tech' => 'glpi_users'],
        'glpi_domainrecords' => ['users_id_tech' => 'glpi_users'],
        'glpi_domains' => ['users_id_tech' => 'glpi_users'],
        'glpi_enclosures' => ['users_id_tech' => 'glpi_users'],
        'glpi_items_devicesimcards' => ['users_id' => 'glpi_users'],
        'glpi_lines' => ['users_id' => 'glpi_users'],
        'glpi_monitors' => ['users_id' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
        'glpi_networkequipments' => ['users_id' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
        'glpi_passivedcequipments' => ['users_id_tech' => 'glpi_users'],
        'glpi_pdus' => ['users_id_tech' => 'glpi_users'],
        'glpi_peripherals' => ['users_id' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
        'glpi_phones' => ['users_id' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
        'glpi_printers' => ['users_id' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
        'glpi_racks' => ['users_id_tech' => 'glpi_users'],
        'glpi_softwarelicenses' => ['users_id' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
        'glpi_softwares' => ['users_id' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
    ];

    public const RESERVATION_USERS = [
        'glpi_reservations' => ['users_id' => 'glpi_users'],
    ];

    public const CONTENT_METADATA = [
        'glpi_documents' => ['users_id' => 'glpi_users', 'documentcategories_id' => 'glpi_documentcategories'],
        'glpi_documents_items' => ['users_id' => 'glpi_users'],
        'glpi_knowbaseitems' => ['users_id' => 'glpi_users'],
        'glpi_knowbaseitems_comments' => ['users_id' => 'glpi_users'],
        'glpi_knowbaseitems_revisions' => ['users_id' => 'glpi_users'],
        'glpi_knowbaseitemtranslations' => ['users_id' => 'glpi_users'],
        'glpi_notepads' => ['users_id' => 'glpi_users', 'users_id_lastupdater' => 'glpi_users'],
    ];

    public const ARTICLE_CATEGORIES = [
        'glpi_knowbaseitems' => ['knowbaseitemcategories_id' => 'glpi_knowbaseitemcategories'],
    ];

    public const SOFTWARE_METADATA = [
        'glpi_softwares' => ['softwares_id' => 'glpi_softwares', 'softwarecategories_id' => 'glpi_softwarecategories'],
        'glpi_softwarelicenses' => ['softwarelicenses_id' => 'glpi_softwarelicenses', 'softwareversions_id_buy' => 'glpi_softwareversions', 'softwareversions_id_use' => 'glpi_softwareversions'],
    ];

    public const CONTACT_LINE_METADATA = [
        'glpi_contacts' => ['contacttypes_id' => 'glpi_contacttypes', 'usertitles_id' => 'glpi_usertitles'],
        'glpi_lines' => ['lineoperators_id' => 'glpi_lineoperators', 'linetypes_id' => 'glpi_linetypes'],
        'glpi_items_devicesimcards' => ['lines_id' => 'glpi_lines'],
    ];

    public const QUEUE_TEMPLATES = [
        'glpi_queuednotifications' => ['notificationtemplates_id' => 'glpi_notificationtemplates'],
        'glpi_queuedchats' => ['notificationtemplates_id' => 'glpi_notificationtemplates'],
    ];

    public const REJECTED_EMAIL_REFERENCES = [
        'glpi_notimportedemails' => ['mailcollectors_id' => 'glpi_mailcollectors', 'users_id' => 'glpi_users'],
    ];

    public const PERSONAL_CONTENT_OWNERS = [
        'glpi_reminders' => ['users_id' => 'glpi_users'],
        'glpi_remindertranslations' => ['users_id' => 'glpi_users'],
        'glpi_rssfeeds' => ['users_id' => 'glpi_users'],
    ];

    public const PLANNING_OWNERS = [
        'glpi_projects' => ['users_id' => 'glpi_users'],
        'glpi_projecttasks' => ['users_id' => 'glpi_users'],
        'glpi_projecttasktemplates' => ['users_id' => 'glpi_users'],
        'glpi_planningexternalevents' => ['users_id' => 'glpi_users'],
    ];

    public const SERVICE_LEVELS = ['glpi_tickets' => [
        'slas_id_tto' => 'glpi_slas', 'slas_id_ttr' => 'glpi_slas', 'slalevels_id_ttr' => 'glpi_slalevels',
        'olas_id_tto' => 'glpi_olas', 'olas_id_ttr' => 'glpi_olas', 'olalevels_id_ttr' => 'glpi_olalevels',
    ]];

    public const SAVED_SEARCHES = ['glpi_savedsearches' => ['users_id' => 'glpi_users']];

    public const ITIL_DEFAULTS = [
        'glpi_profiles' => ['tickettemplates_id' => 'glpi_tickettemplates', 'changetemplates_id' => 'glpi_changetemplates', 'problemtemplates_id' => 'glpi_problemtemplates'],
        'glpi_ticketrecurrents' => ['tickettemplates_id' => 'glpi_tickettemplates', 'calendars_id' => 'glpi_calendars'],
        'glpi_itilcategories' => ['users_id' => 'glpi_users'],
        'glpi_tasktemplates' => ['users_id_tech' => 'glpi_users'],
    ];

    public const NETWORK_NAMES = [
        'glpi_networknames' => ['fqdns_id' => 'glpi_fqdns'],
        'glpi_networkaliases' => ['fqdns_id' => 'glpi_fqdns'],
    ];

    public const NETWORK_PORT_METADATA = [
        'glpi_networkportaliases' => ['networkports_id_alias' => 'glpi_networkports'],
        'glpi_networkportethernets' => ['items_devicenetworkcards_id' => 'glpi_items_devicenetworkcards', 'netpoints_id' => 'glpi_netpoints'],
        'glpi_networkportfiberchannels' => ['items_devicenetworkcards_id' => 'glpi_items_devicenetworkcards', 'netpoints_id' => 'glpi_netpoints'],
        'glpi_networkportwifis' => ['items_devicenetworkcards_id' => 'glpi_items_devicenetworkcards', 'wifinetworks_id' => 'glpi_wifinetworks', 'networkportwifis_id' => 'glpi_networkportwifis'],
    ];

    public const ITIL_ACTORS = [
        'glpi_tickets_users' => ['users_id' => 'glpi_users'],
        'glpi_problems_users' => ['users_id' => 'glpi_users'],
        'glpi_changes_users' => ['users_id' => 'glpi_users'],
        'glpi_suppliers_tickets' => ['suppliers_id' => 'glpi_suppliers'],
        'glpi_problems_suppliers' => ['suppliers_id' => 'glpi_suppliers'],
        'glpi_changes_suppliers' => ['suppliers_id' => 'glpi_suppliers'],
    ];

    public const ITIL_USERS = [
        'glpi_tickets' => ['users_id_recipient' => 'glpi_users', 'users_id_lastupdater' => 'glpi_users'],
        'glpi_problems' => ['users_id_recipient' => 'glpi_users', 'users_id_lastupdater' => 'glpi_users'],
        'glpi_changes' => ['users_id_recipient' => 'glpi_users', 'users_id_lastupdater' => 'glpi_users'],
        'glpi_tickettasks' => ['users_id' => 'glpi_users', 'users_id_editor' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
        'glpi_problemtasks' => ['users_id' => 'glpi_users', 'users_id_editor' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
        'glpi_changetasks' => ['users_id' => 'glpi_users', 'users_id_editor' => 'glpi_users', 'users_id_tech' => 'glpi_users'],
        'glpi_ticketvalidations' => ['users_id' => 'glpi_users', 'users_id_validate' => 'glpi_users'],
        'glpi_changevalidations' => ['users_id' => 'glpi_users', 'users_id_validate' => 'glpi_users'],
        'glpi_itilfollowups' => ['users_id' => 'glpi_users', 'users_id_editor' => 'glpi_users'],
        'glpi_itilsolutions' => ['users_id' => 'glpi_users', 'users_id_editor' => 'glpi_users', 'users_id_approval' => 'glpi_users'],
    ];

    public const USER_METADATA = ['glpi_users' => ['profiles_id' => 'glpi_profiles', 'usertitles_id' => 'glpi_usertitles', 'usercategories_id' => 'glpi_usercategories', 'users_id_supervisor' => 'glpi_users']];

    public const CRON_LOG_PARENTS = ['glpi_crontasklogs' => ['crontasklogs_id' => 'glpi_crontasklogs']];

    public const RELATIONS = [
        ...self::QUEUE_TEMPLATES,
        ...self::REJECTED_EMAIL_REFERENCES,
        ...self::PERSONAL_CONTENT_OWNERS,
        'glpi_slms' => ['calendars_id' => 'glpi_calendars'],
        ...self::SAVED_SEARCHES,
        'glpi_profiles' => self::ITIL_DEFAULTS['glpi_profiles'],
        'glpi_ticketrecurrents' => self::ITIL_DEFAULTS['glpi_ticketrecurrents'],
        ...self::NETWORK_NAMES,
        ...self::NETWORK_PORT_METADATA,
        ...self::ITIL_ACTORS,
        ...self::CRON_LOG_PARENTS,
        ...self::CONTACT_LINE_METADATA,
        ...self::CONTENT_METADATA,
        'glpi_knowbaseitems' => [...self::CONTENT_METADATA['glpi_knowbaseitems'], ...self::ARTICLE_CATEGORIES['glpi_knowbaseitems']],
        ...self::RESERVATION_USERS,
        ...self::ASSET_USERS,
        ...self::TREE_PARENTS,
        ...self::ITIL_CLASSIFICATION,
        ...self::PLANNING_METADATA,
        'glpi_projecttasktemplates' => [...self::PLANNING_METADATA['glpi_projecttasktemplates'], ...self::PLANNING_OWNERS['glpi_projecttasktemplates']],
        ...self::INVENTORY_METADATA,
        ...self::MODELS,
        ...self::PROJECT_HIERARCHY,
        ...self::INFRASTRUCTURE,
        ...self::ASSET_CLASSIFICATION,
        ...self::STOCK,
        ...self::FINANCIAL,
        ...self::FINANCIAL_METADATA,
        ...self::MANUFACTURERS,
        ...self::STATES,
        ...self::LOCATIONS,
        ...self::GROUPS,
        'glpi_appliances' => [...self::INFRASTRUCTURE['glpi_appliances'], ...self::MANUFACTURERS['glpi_appliances'], ...self::STATES['glpi_appliances'], ...self::LOCATIONS['glpi_appliances'], ...self::GROUPS['glpi_appliances'], ...self::ASSET_USERS['glpi_appliances']],
        'glpi_budgets' => [...self::FINANCIAL['glpi_budgets'], ...self::FINANCIAL_METADATA['glpi_budgets']],
        'glpi_cartridgeitems' => [...self::STOCK['glpi_cartridgeitems'], ...self::MANUFACTURERS['glpi_cartridgeitems'], ...self::LOCATIONS['glpi_cartridgeitems'], ...self::GROUPS['glpi_cartridgeitems'], ...self::ASSET_USERS['glpi_cartridgeitems']],
        'glpi_certificates' => [...self::INFRASTRUCTURE['glpi_certificates'], ...self::MANUFACTURERS['glpi_certificates'], ...self::STATES['glpi_certificates'], ...self::LOCATIONS['glpi_certificates'], ...self::GROUPS['glpi_certificates'], ...self::ASSET_USERS['glpi_certificates']],
        'glpi_clusters' => [...self::INFRASTRUCTURE['glpi_clusters'], ...self::STATES['glpi_clusters'], ...self::GROUPS['glpi_clusters'], ...self::INVENTORY_METADATA['glpi_clusters'], ...self::ASSET_USERS['glpi_clusters']],
        'glpi_computers' => [...self::ASSET_CLASSIFICATION['glpi_computers'], ...self::MANUFACTURERS['glpi_computers'], ...self::STATES['glpi_computers'], ...self::LOCATIONS['glpi_computers'], ...self::GROUPS['glpi_computers'], ...self::INVENTORY_METADATA['glpi_computers'], ...self::ASSET_USERS['glpi_computers']],
        'glpi_consumableitems' => [...self::STOCK['glpi_consumableitems'], ...self::MANUFACTURERS['glpi_consumableitems'], ...self::LOCATIONS['glpi_consumableitems'], ...self::GROUPS['glpi_consumableitems'], ...self::ASSET_USERS['glpi_consumableitems']],
        'glpi_contracts' => [...self::FINANCIAL['glpi_contracts'], ...self::FINANCIAL_METADATA['glpi_contracts']],
        'glpi_dcrooms' => [...self::INFRASTRUCTURE['glpi_dcrooms'], ...self::LOCATIONS['glpi_dcrooms']],
        'glpi_devicebatteries' => [...self::MODELS['glpi_devicebatteries'], ...self::MANUFACTURERS['glpi_devicebatteries'], ...self::INVENTORY_METADATA['glpi_devicebatteries']],
        'glpi_devicecases' => [...self::MODELS['glpi_devicecases'], ...self::MANUFACTURERS['glpi_devicecases'], ...self::INVENTORY_METADATA['glpi_devicecases']],
        'glpi_devicecontrols' => [...self::MODELS['glpi_devicecontrols'], ...self::MANUFACTURERS['glpi_devicecontrols'], ...self::INVENTORY_METADATA['glpi_devicecontrols']],
        'glpi_devicedrives' => [...self::MODELS['glpi_devicedrives'], ...self::MANUFACTURERS['glpi_devicedrives'], ...self::INVENTORY_METADATA['glpi_devicedrives']],
        'glpi_devicefirmwares' => [...self::MODELS['glpi_devicefirmwares'], ...self::MANUFACTURERS['glpi_devicefirmwares'], ...self::INVENTORY_METADATA['glpi_devicefirmwares']],
        'glpi_devicegenerics' => [...self::MODELS['glpi_devicegenerics'], ...self::MANUFACTURERS['glpi_devicegenerics'], ...self::STATES['glpi_devicegenerics'], ...self::LOCATIONS['glpi_devicegenerics'], ...self::INVENTORY_METADATA['glpi_devicegenerics']],
        'glpi_devicegraphiccards' => [...self::MODELS['glpi_devicegraphiccards'], ...self::MANUFACTURERS['glpi_devicegraphiccards'], ...self::INVENTORY_METADATA['glpi_devicegraphiccards']],
        'glpi_deviceharddrives' => [...self::MODELS['glpi_deviceharddrives'], ...self::MANUFACTURERS['glpi_deviceharddrives'], ...self::INVENTORY_METADATA['glpi_deviceharddrives']],
        'glpi_devicememories' => [...self::MODELS['glpi_devicememories'], ...self::MANUFACTURERS['glpi_devicememories'], ...self::INVENTORY_METADATA['glpi_devicememories']],
        'glpi_devicemotherboards' => [...self::MODELS['glpi_devicemotherboards'], ...self::MANUFACTURERS['glpi_devicemotherboards']],
        'glpi_devicenetworkcards' => [...self::MODELS['glpi_devicenetworkcards'], ...self::MANUFACTURERS['glpi_devicenetworkcards']],
        'glpi_devicepcis' => [...self::MODELS['glpi_devicepcis'], ...self::LEGACY_COMPONENT_MODELS['glpi_devicepcis'], ...self::MANUFACTURERS['glpi_devicepcis']],
        'glpi_devicepowersupplies' => [...self::MODELS['glpi_devicepowersupplies'], ...self::MANUFACTURERS['glpi_devicepowersupplies']],
        'glpi_deviceprocessors' => [...self::MODELS['glpi_deviceprocessors'], ...self::MANUFACTURERS['glpi_deviceprocessors']],
        'glpi_devicesensors' => [...self::MANUFACTURERS['glpi_devicesensors'], ...self::STATES['glpi_devicesensors'], ...self::LOCATIONS['glpi_devicesensors'], ...self::INVENTORY_METADATA['glpi_devicesensors']],
        'glpi_devicesimcards' => [...self::MANUFACTURERS['glpi_devicesimcards'], ...self::INVENTORY_METADATA['glpi_devicesimcards']],
        'glpi_devicesoundcards' => [...self::MODELS['glpi_devicesoundcards'], ...self::MANUFACTURERS['glpi_devicesoundcards']],
        'glpi_domainrecords' => [...self::INFRASTRUCTURE['glpi_domainrecords'], ...self::GROUPS['glpi_domainrecords'], ...self::ASSET_USERS['glpi_domainrecords']],
        'glpi_domains' => [...self::INFRASTRUCTURE['glpi_domains'], ...self::GROUPS['glpi_domains'], ...self::ASSET_USERS['glpi_domains']],
        'glpi_enclosures' => [...self::MODELS['glpi_enclosures'], ...self::MANUFACTURERS['glpi_enclosures'], ...self::STATES['glpi_enclosures'], ...self::LOCATIONS['glpi_enclosures'], ...self::GROUPS['glpi_enclosures'], ...self::ASSET_USERS['glpi_enclosures']],
        'glpi_infocoms' => [...self::FINANCIAL['glpi_infocoms'], ...self::FINANCIAL_METADATA['glpi_infocoms']],
        'glpi_items_devicebatteries' => [...self::STATES['glpi_items_devicebatteries'], ...self::LOCATIONS['glpi_items_devicebatteries']],
        'glpi_items_devicecases' => [...self::STATES['glpi_items_devicecases'], ...self::LOCATIONS['glpi_items_devicecases']],
        'glpi_items_devicecontrols' => [...self::STATES['glpi_items_devicecontrols'], ...self::LOCATIONS['glpi_items_devicecontrols']],
        'glpi_items_devicedrives' => [...self::STATES['glpi_items_devicedrives'], ...self::LOCATIONS['glpi_items_devicedrives']],
        'glpi_items_devicefirmwares' => [...self::STATES['glpi_items_devicefirmwares'], ...self::LOCATIONS['glpi_items_devicefirmwares']],
        'glpi_items_devicegenerics' => [...self::STATES['glpi_items_devicegenerics'], ...self::LOCATIONS['glpi_items_devicegenerics']],
        'glpi_items_devicegraphiccards' => [...self::STATES['glpi_items_devicegraphiccards'], ...self::LOCATIONS['glpi_items_devicegraphiccards']],
        'glpi_items_deviceharddrives' => [...self::STATES['glpi_items_deviceharddrives'], ...self::LOCATIONS['glpi_items_deviceharddrives']],
        'glpi_items_devicememories' => [...self::STATES['glpi_items_devicememories'], ...self::LOCATIONS['glpi_items_devicememories']],
        'glpi_items_devicemotherboards' => [...self::STATES['glpi_items_devicemotherboards'], ...self::LOCATIONS['glpi_items_devicemotherboards']],
        'glpi_items_devicenetworkcards' => [...self::STATES['glpi_items_devicenetworkcards'], ...self::LOCATIONS['glpi_items_devicenetworkcards']],
        'glpi_items_devicepcis' => [...self::STATES['glpi_items_devicepcis'], ...self::LOCATIONS['glpi_items_devicepcis']],
        'glpi_items_devicepowersupplies' => [...self::STATES['glpi_items_devicepowersupplies'], ...self::LOCATIONS['glpi_items_devicepowersupplies']],
        'glpi_items_deviceprocessors' => [...self::STATES['glpi_items_deviceprocessors'], ...self::LOCATIONS['glpi_items_deviceprocessors']],
        'glpi_items_devicesensors' => [...self::STATES['glpi_items_devicesensors'], ...self::LOCATIONS['glpi_items_devicesensors']],
        'glpi_items_devicesimcards' => [...self::STATES['glpi_items_devicesimcards'], ...self::LOCATIONS['glpi_items_devicesimcards'], ...self::GROUPS['glpi_items_devicesimcards'], ...self::ASSET_USERS['glpi_items_devicesimcards'], ...self::CONTACT_LINE_METADATA['glpi_items_devicesimcards']],
        'glpi_items_devicesoundcards' => [...self::STATES['glpi_items_devicesoundcards'], ...self::LOCATIONS['glpi_items_devicesoundcards']],
        'glpi_lines' => [...self::STATES['glpi_lines'], ...self::LOCATIONS['glpi_lines'], ...self::GROUPS['glpi_lines'], ...self::ASSET_USERS['glpi_lines'], ...self::CONTACT_LINE_METADATA['glpi_lines']],
        'glpi_monitors' => [...self::ASSET_CLASSIFICATION['glpi_monitors'], ...self::MANUFACTURERS['glpi_monitors'], ...self::STATES['glpi_monitors'], ...self::LOCATIONS['glpi_monitors'], ...self::GROUPS['glpi_monitors'], ...self::ASSET_USERS['glpi_monitors']],
        'glpi_networkequipments' => [...self::ASSET_CLASSIFICATION['glpi_networkequipments'], ...self::MANUFACTURERS['glpi_networkequipments'], ...self::STATES['glpi_networkequipments'], ...self::LOCATIONS['glpi_networkequipments'], ...self::GROUPS['glpi_networkequipments'], ...self::INVENTORY_METADATA['glpi_networkequipments'], ...self::ASSET_USERS['glpi_networkequipments']],
        'glpi_passivedcequipments' => [...self::MODELS['glpi_passivedcequipments'], ...self::MANUFACTURERS['glpi_passivedcequipments'], ...self::STATES['glpi_passivedcequipments'], ...self::LOCATIONS['glpi_passivedcequipments'], ...self::GROUPS['glpi_passivedcequipments'], ...self::INVENTORY_METADATA['glpi_passivedcequipments'], ...self::ASSET_USERS['glpi_passivedcequipments']],
        'glpi_pdus' => [...self::MODELS['glpi_pdus'], ...self::INFRASTRUCTURE['glpi_pdus'], ...self::MANUFACTURERS['glpi_pdus'], ...self::STATES['glpi_pdus'], ...self::LOCATIONS['glpi_pdus'], ...self::GROUPS['glpi_pdus'], ...self::ASSET_USERS['glpi_pdus']],
        'glpi_peripherals' => [...self::ASSET_CLASSIFICATION['glpi_peripherals'], ...self::MANUFACTURERS['glpi_peripherals'], ...self::STATES['glpi_peripherals'], ...self::LOCATIONS['glpi_peripherals'], ...self::GROUPS['glpi_peripherals'], ...self::ASSET_USERS['glpi_peripherals']],
        'glpi_phones' => [...self::ASSET_CLASSIFICATION['glpi_phones'], ...self::MANUFACTURERS['glpi_phones'], ...self::STATES['glpi_phones'], ...self::LOCATIONS['glpi_phones'], ...self::GROUPS['glpi_phones'], ...self::INVENTORY_METADATA['glpi_phones'], ...self::ASSET_USERS['glpi_phones']],
        'glpi_printers' => [...self::ASSET_CLASSIFICATION['glpi_printers'], ...self::MANUFACTURERS['glpi_printers'], ...self::STATES['glpi_printers'], ...self::LOCATIONS['glpi_printers'], ...self::GROUPS['glpi_printers'], ...self::INVENTORY_METADATA['glpi_printers'], ...self::ASSET_USERS['glpi_printers']],
        'glpi_projects' => [...self::PLANNING_OWNERS['glpi_projects'], ...self::PROJECT_HIERARCHY['glpi_projects'], ...self::GROUPS['glpi_projects'], ...self::PLANNING_METADATA['glpi_projects']],
        'glpi_queuedchats' => [...self::QUEUE_TEMPLATES['glpi_queuedchats'], ...self::LOCATIONS['glpi_queuedchats'], ...self::GROUPS['glpi_queuedchats'], ...self::ITIL_CLASSIFICATION['glpi_queuedchats']],
        'glpi_racks' => [...self::MODELS['glpi_racks'], ...self::INFRASTRUCTURE['glpi_racks'], ...self::MANUFACTURERS['glpi_racks'], ...self::STATES['glpi_racks'], ...self::LOCATIONS['glpi_racks'], ...self::GROUPS['glpi_racks'], ...self::ASSET_USERS['glpi_racks']],
        'glpi_softwarelicenses' => [...self::FINANCIAL['glpi_softwarelicenses'], ...self::MANUFACTURERS['glpi_softwarelicenses'], ...self::STATES['glpi_softwarelicenses'], ...self::LOCATIONS['glpi_softwarelicenses'], ...self::GROUPS['glpi_softwarelicenses'], ...self::ASSET_USERS['glpi_softwarelicenses'], ...self::SOFTWARE_METADATA['glpi_softwarelicenses']],
        'glpi_softwares' => [...self::MANUFACTURERS['glpi_softwares'], ...self::LOCATIONS['glpi_softwares'], ...self::GROUPS['glpi_softwares'], ...self::ASSET_USERS['glpi_softwares'], ...self::SOFTWARE_METADATA['glpi_softwares']],
        'glpi_softwareversions' => [...self::STATES['glpi_softwareversions'], ...self::INVENTORY_METADATA['glpi_softwareversions']],
        'glpi_users' => [...self::USER_METADATA['glpi_users'], ...self::LOCATIONS['glpi_users'], ...self::GROUPS['glpi_users'], ...self::ITIL_CLASSIFICATION['glpi_users']],
        'glpi_projecttasks' => [...self::PLANNING_OWNERS['glpi_projecttasks'], ...self::PROJECT_HIERARCHY['glpi_projecttasks'], ...self::PLANNING_METADATA['glpi_projecttasks']],
        'glpi_planningexternalevents' => [...self::PLANNING_OWNERS['glpi_planningexternalevents'], ...self::GROUPS['glpi_planningexternalevents'], ...self::PLANNING_METADATA['glpi_planningexternalevents']],
        'glpi_tickets' => [...self::SERVICE_LEVELS['glpi_tickets'], ...self::LOCATIONS['glpi_tickets'], ...self::ITIL_CLASSIFICATION['glpi_tickets'], ...self::ITIL_USERS['glpi_tickets']],
        'glpi_changetasks' => [...self::GROUPS['glpi_changetasks'], ...self::ITIL_CLASSIFICATION['glpi_changetasks'], ...self::ITIL_USERS['glpi_changetasks']],
        'glpi_problemtasks' => [...self::GROUPS['glpi_problemtasks'], ...self::ITIL_CLASSIFICATION['glpi_problemtasks'], ...self::ITIL_USERS['glpi_problemtasks']],
        'glpi_tickettasks' => [...self::GROUPS['glpi_tickettasks'], ...self::ITIL_CLASSIFICATION['glpi_tickettasks'], ...self::ITIL_USERS['glpi_tickettasks']],
        'glpi_itilcategories' => [...self::ITIL_DEFAULTS['glpi_itilcategories'], ...self::GROUPS['glpi_itilcategories'], ...self::ITIL_CLASSIFICATION['glpi_itilcategories'], ...self::TREE_PARENTS['glpi_itilcategories']],
        'glpi_tasktemplates' => [...self::ITIL_DEFAULTS['glpi_tasktemplates'], ...self::GROUPS['glpi_tasktemplates'], ...self::ITIL_CLASSIFICATION['glpi_tasktemplates']],
        'glpi_taskcategories' => ['knowbaseitemcategories_id' => 'glpi_knowbaseitemcategories', ...self::TREE_PARENTS['glpi_taskcategories']],
        'glpi_problems' => [...self::ITIL_CLASSIFICATION['glpi_problems'], ...self::ITIL_USERS['glpi_problems']],
        'glpi_changes' => [...self::ITIL_CLASSIFICATION['glpi_changes'], ...self::ITIL_USERS['glpi_changes']],
        'glpi_ticketvalidations' => [...self::ITIL_USERS['glpi_ticketvalidations']],
        'glpi_changevalidations' => [...self::ITIL_USERS['glpi_changevalidations']],
        'glpi_itilfollowups' => [...self::ITIL_CLASSIFICATION['glpi_itilfollowups'], ...self::ITIL_USERS['glpi_itilfollowups']],
        'glpi_itilsolutions' => [...self::ITIL_CLASSIFICATION['glpi_itilsolutions'], ...self::ITIL_USERS['glpi_itilsolutions']],
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
