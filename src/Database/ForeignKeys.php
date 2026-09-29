<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Schema;

/** Explicit relationships: never infer a foreign key from a column's name. */
final class ForeignKeys
{
    /**
     * Non-polymorphic associations with valid targets or SQL NULL. Parent purge
     * hooks remove or reassign children; RESTRICT keeps those hooks authoritative.
     * Anonymous email actors have a nullable user/supplier association.
     * entities_id=0 is a real root entity, not an absent relationship.
     */
    public const RELATIONS = [
        ...OptionalReferences::RELATIONS,
        ...EntityOwnership::RELATIONS,
        'glpi_planningrecalls' => ['users_id' => 'glpi_users'],
        'glpi_slms' => [...OptionalReferences::RELATIONS['glpi_slms'], ...EntityOwnership::RELATIONS['glpi_slms']],
        'glpi_slas' => [...EntityOwnership::RELATIONS['glpi_slas'], 'slms_id' => 'glpi_slms'],
        'glpi_slalevels' => [...EntityOwnership::RELATIONS['glpi_slalevels'], 'slas_id' => 'glpi_slas'],
        'glpi_slalevelactions' => ['slalevels_id' => 'glpi_slalevels'],
        'glpi_slalevelcriterias' => ['slalevels_id' => 'glpi_slalevels'],
        'glpi_slalevels_tickets' => ['slalevels_id' => 'glpi_slalevels', 'tickets_id' => 'glpi_tickets'],
        'glpi_olas' => [...EntityOwnership::RELATIONS['glpi_olas'], 'slms_id' => 'glpi_slms'],
        'glpi_olalevels' => [...EntityOwnership::RELATIONS['glpi_olalevels'], 'olas_id' => 'glpi_olas'],
        'glpi_olalevelactions' => ['olalevels_id' => 'glpi_olalevels'],
        'glpi_olalevelcriterias' => ['olalevels_id' => 'glpi_olalevels'],
        'glpi_olalevels_tickets' => ['olalevels_id' => 'glpi_olalevels', 'tickets_id' => 'glpi_tickets'],
        'glpi_appliances' => [...OptionalReferences::RELATIONS['glpi_appliances'], ...EntityOwnership::RELATIONS['glpi_appliances']],
        'glpi_appliances_items' => ['appliances_id' => 'glpi_appliances'],
        'glpi_appliances_items_relations' => ['appliances_items_id' => 'glpi_appliances_items'],
        'glpi_budgets' => [...OptionalReferences::RELATIONS['glpi_budgets'], ...EntityOwnership::RELATIONS['glpi_budgets']],
        'glpi_calendars_holidays' => ['calendars_id' => 'glpi_calendars', 'holidays_id' => 'glpi_holidays'],
        'glpi_calendarsegments' => [...EntityOwnership::RELATIONS['glpi_calendarsegments'], 'calendars_id' => 'glpi_calendars'],
        'glpi_cartridgeitems' => [...OptionalReferences::RELATIONS['glpi_cartridgeitems'], ...EntityOwnership::RELATIONS['glpi_cartridgeitems']],
        'glpi_cartridgeitems_printermodels' => ['cartridgeitems_id' => 'glpi_cartridgeitems', 'printermodels_id' => 'glpi_printermodels'],
        'glpi_cartridges' => [...OptionalReferences::RELATIONS['glpi_cartridges'], ...EntityOwnership::RELATIONS['glpi_cartridges'], 'cartridgeitems_id' => 'glpi_cartridgeitems'],
        'glpi_certificates' => [...OptionalReferences::RELATIONS['glpi_certificates'], ...EntityOwnership::RELATIONS['glpi_certificates']],
        'glpi_certificates_items' => ['certificates_id' => 'glpi_certificates'],
        'glpi_changecosts' => [...OptionalReferences::RELATIONS['glpi_changecosts'], ...EntityOwnership::RELATIONS['glpi_changecosts'], 'changes_id' => 'glpi_changes'],
        'glpi_changes_groups' => ['changes_id' => 'glpi_changes', 'groups_id' => 'glpi_groups'],
        'glpi_changes_items' => ['changes_id' => 'glpi_changes'],
        'glpi_changes_problems' => ['changes_id' => 'glpi_changes', 'problems_id' => 'glpi_problems'],
        'glpi_changes_suppliers' => [...OptionalReferences::RELATIONS['glpi_changes_suppliers'], 'changes_id' => 'glpi_changes'],
        'glpi_changes_tickets' => ['changes_id' => 'glpi_changes', 'tickets_id' => 'glpi_tickets'],
        'glpi_changes_users' => [...OptionalReferences::RELATIONS['glpi_changes_users'], 'changes_id' => 'glpi_changes'],
        'glpi_changetasks' => [...OptionalReferences::RELATIONS['glpi_changetasks'], 'changes_id' => 'glpi_changes'],
        'glpi_changetemplatehiddenfields' => ['changetemplates_id' => 'glpi_changetemplates'],
        'glpi_changetemplatemandatoryfields' => ['changetemplates_id' => 'glpi_changetemplates'],
        'glpi_changetemplatepredefinedfields' => ['changetemplates_id' => 'glpi_changetemplates'],
        'glpi_changevalidations' => [...OptionalReferences::RELATIONS['glpi_changevalidations'], ...EntityOwnership::RELATIONS['glpi_changevalidations'], 'changes_id' => 'glpi_changes'],
        'glpi_clusters' => [...OptionalReferences::RELATIONS['glpi_clusters'], ...EntityOwnership::RELATIONS['glpi_clusters']],
        'glpi_computerantiviruses' => [...OptionalReferences::RELATIONS['glpi_computerantiviruses'], 'computers_id' => 'glpi_computers'],
        'glpi_computers' => [...OptionalReferences::RELATIONS['glpi_computers'], ...EntityOwnership::RELATIONS['glpi_computers']],
        'glpi_computers_items' => ['computers_id' => 'glpi_computers'],
        'glpi_computervirtualmachines' => [...OptionalReferences::RELATIONS['glpi_computervirtualmachines'], ...EntityOwnership::RELATIONS['glpi_computervirtualmachines']],
        'glpi_consumableitems' => [...OptionalReferences::RELATIONS['glpi_consumableitems'], ...EntityOwnership::RELATIONS['glpi_consumableitems']],
        'glpi_consumables' => [...EntityOwnership::RELATIONS['glpi_consumables'], 'consumableitems_id' => 'glpi_consumableitems'],
        'glpi_contacts' => [...OptionalReferences::RELATIONS['glpi_contacts'], ...EntityOwnership::RELATIONS['glpi_contacts']],
        'glpi_crontasklogs' => [...OptionalReferences::RELATIONS['glpi_crontasklogs'], 'crontasks_id' => 'glpi_crontasks'],
        'glpi_contacts_suppliers' => ['contacts_id' => 'glpi_contacts', 'suppliers_id' => 'glpi_suppliers'],
        'glpi_contractcosts' => [...OptionalReferences::RELATIONS['glpi_contractcosts'], ...EntityOwnership::RELATIONS['glpi_contractcosts'], 'contracts_id' => 'glpi_contracts'],
        'glpi_contracts' => [...OptionalReferences::RELATIONS['glpi_contracts'], ...EntityOwnership::RELATIONS['glpi_contracts']],
        'glpi_contracts_items' => ['contracts_id' => 'glpi_contracts'],
        'glpi_contracts_suppliers' => ['contracts_id' => 'glpi_contracts', 'suppliers_id' => 'glpi_suppliers'],
        'glpi_datacenters' => [...OptionalReferences::RELATIONS['glpi_datacenters'], ...EntityOwnership::RELATIONS['glpi_datacenters']],
        'glpi_dcrooms' => [...OptionalReferences::RELATIONS['glpi_dcrooms'], ...EntityOwnership::RELATIONS['glpi_dcrooms']],
        'glpi_devicebatteries' => [...OptionalReferences::RELATIONS['glpi_devicebatteries'], ...EntityOwnership::RELATIONS['glpi_devicebatteries']],
        'glpi_devicecases' => [...OptionalReferences::RELATIONS['glpi_devicecases'], ...EntityOwnership::RELATIONS['glpi_devicecases']],
        'glpi_devicecontrols' => [...OptionalReferences::RELATIONS['glpi_devicecontrols'], ...EntityOwnership::RELATIONS['glpi_devicecontrols']],
        'glpi_devicedrives' => [...OptionalReferences::RELATIONS['glpi_devicedrives'], ...EntityOwnership::RELATIONS['glpi_devicedrives']],
        'glpi_devicefirmwares' => [...OptionalReferences::RELATIONS['glpi_devicefirmwares'], ...EntityOwnership::RELATIONS['glpi_devicefirmwares']],
        'glpi_devicegenerics' => [...OptionalReferences::RELATIONS['glpi_devicegenerics'], ...EntityOwnership::RELATIONS['glpi_devicegenerics']],
        'glpi_devicegraphiccards' => [...OptionalReferences::RELATIONS['glpi_devicegraphiccards'], ...EntityOwnership::RELATIONS['glpi_devicegraphiccards']],
        'glpi_deviceharddrives' => [...OptionalReferences::RELATIONS['glpi_deviceharddrives'], ...EntityOwnership::RELATIONS['glpi_deviceharddrives']],
        'glpi_devicememories' => [...OptionalReferences::RELATIONS['glpi_devicememories'], ...EntityOwnership::RELATIONS['glpi_devicememories']],
        'glpi_devicemotherboards' => [...OptionalReferences::RELATIONS['glpi_devicemotherboards'], ...EntityOwnership::RELATIONS['glpi_devicemotherboards']],
        'glpi_devicenetworkcards' => [...OptionalReferences::RELATIONS['glpi_devicenetworkcards'], ...EntityOwnership::RELATIONS['glpi_devicenetworkcards']],
        'glpi_devicepcis' => [...OptionalReferences::RELATIONS['glpi_devicepcis'], ...EntityOwnership::RELATIONS['glpi_devicepcis']],
        'glpi_devicepowersupplies' => [...OptionalReferences::RELATIONS['glpi_devicepowersupplies'], ...EntityOwnership::RELATIONS['glpi_devicepowersupplies']],
        'glpi_deviceprocessors' => [...OptionalReferences::RELATIONS['glpi_deviceprocessors'], ...EntityOwnership::RELATIONS['glpi_deviceprocessors']],
        'glpi_devicesensors' => [...OptionalReferences::RELATIONS['glpi_devicesensors'], ...EntityOwnership::RELATIONS['glpi_devicesensors']],
        'glpi_devicesimcards' => [...OptionalReferences::RELATIONS['glpi_devicesimcards'], ...EntityOwnership::RELATIONS['glpi_devicesimcards']],
        'glpi_devicesoundcards' => [...OptionalReferences::RELATIONS['glpi_devicesoundcards'], ...EntityOwnership::RELATIONS['glpi_devicesoundcards']],
        'glpi_documents' => [...OptionalReferences::RELATIONS['glpi_documents'], ...EntityOwnership::RELATIONS['glpi_documents']],
        'glpi_documents_items' => [...OptionalReferences::RELATIONS['glpi_documents_items'], ...EntityOwnership::RELATIONS['glpi_documents_items'], 'documents_id' => 'glpi_documents'],
        'glpi_domainrecords' => [...OptionalReferences::RELATIONS['glpi_domainrecords'], ...EntityOwnership::RELATIONS['glpi_domainrecords'], 'domains_id' => 'glpi_domains'],
        'glpi_domains' => [...OptionalReferences::RELATIONS['glpi_domains'], ...EntityOwnership::RELATIONS['glpi_domains']],
        'glpi_domains_items' => [...OptionalReferences::RELATIONS['glpi_domains_items'], 'domains_id' => 'glpi_domains'],
        'glpi_enclosures' => [...OptionalReferences::RELATIONS['glpi_enclosures'], ...EntityOwnership::RELATIONS['glpi_enclosures']],
        'glpi_entities_knowbaseitems' => ['knowbaseitems_id' => 'glpi_knowbaseitems', 'entities_id' => 'glpi_entities'],
        'glpi_entities_reminders' => ['reminders_id' => 'glpi_reminders', 'entities_id' => 'glpi_entities'],
        'glpi_entities_rssfeeds' => ['rssfeeds_id' => 'glpi_rssfeeds', 'entities_id' => 'glpi_entities'],
        'glpi_groups' => [...OptionalReferences::RELATIONS['glpi_groups'], ...EntityOwnership::RELATIONS['glpi_groups']],
        'glpi_groups_knowbaseitems' => ['knowbaseitems_id' => 'glpi_knowbaseitems', 'groups_id' => 'glpi_groups'],
        'glpi_groups_problems' => ['problems_id' => 'glpi_problems', 'groups_id' => 'glpi_groups'],
        'glpi_groups_reminders' => ['reminders_id' => 'glpi_reminders', 'groups_id' => 'glpi_groups'],
        'glpi_groups_rssfeeds' => ['rssfeeds_id' => 'glpi_rssfeeds', 'groups_id' => 'glpi_groups'],
        'glpi_groups_tickets' => ['tickets_id' => 'glpi_tickets', 'groups_id' => 'glpi_groups'],
        'glpi_groups_users' => ['users_id' => 'glpi_users', 'groups_id' => 'glpi_groups'],
        'glpi_infocoms' => [...OptionalReferences::RELATIONS['glpi_infocoms'], ...EntityOwnership::RELATIONS['glpi_infocoms']],
        'glpi_ipaddresses_ipnetworks' => ['ipaddresses_id' => 'glpi_ipaddresses', 'ipnetworks_id' => 'glpi_ipnetworks'],
        'glpi_ipnetworks_vlans' => ['ipnetworks_id' => 'glpi_ipnetworks', 'vlans_id' => 'glpi_vlans'],
        'glpi_items_clusters' => ['clusters_id' => 'glpi_clusters'],
        'glpi_items_devicebatteries' => [...OptionalReferences::RELATIONS['glpi_items_devicebatteries'], ...EntityOwnership::RELATIONS['glpi_items_devicebatteries'], 'devicebatteries_id' => 'glpi_devicebatteries'],
        'glpi_items_devicecases' => [...OptionalReferences::RELATIONS['glpi_items_devicecases'], ...EntityOwnership::RELATIONS['glpi_items_devicecases'], 'devicecases_id' => 'glpi_devicecases'],
        'glpi_items_devicecontrols' => [...OptionalReferences::RELATIONS['glpi_items_devicecontrols'], ...EntityOwnership::RELATIONS['glpi_items_devicecontrols'], 'devicecontrols_id' => 'glpi_devicecontrols'],
        'glpi_items_devicedrives' => [...OptionalReferences::RELATIONS['glpi_items_devicedrives'], ...EntityOwnership::RELATIONS['glpi_items_devicedrives'], 'devicedrives_id' => 'glpi_devicedrives'],
        'glpi_items_devicefirmwares' => [...OptionalReferences::RELATIONS['glpi_items_devicefirmwares'], ...EntityOwnership::RELATIONS['glpi_items_devicefirmwares'], 'devicefirmwares_id' => 'glpi_devicefirmwares'],
        'glpi_items_devicegenerics' => [...OptionalReferences::RELATIONS['glpi_items_devicegenerics'], ...EntityOwnership::RELATIONS['glpi_items_devicegenerics'], 'devicegenerics_id' => 'glpi_devicegenerics'],
        'glpi_items_devicegraphiccards' => [...OptionalReferences::RELATIONS['glpi_items_devicegraphiccards'], ...EntityOwnership::RELATIONS['glpi_items_devicegraphiccards'], 'devicegraphiccards_id' => 'glpi_devicegraphiccards'],
        'glpi_items_deviceharddrives' => [...OptionalReferences::RELATIONS['glpi_items_deviceharddrives'], ...EntityOwnership::RELATIONS['glpi_items_deviceharddrives'], 'deviceharddrives_id' => 'glpi_deviceharddrives'],
        'glpi_items_devicememories' => [...OptionalReferences::RELATIONS['glpi_items_devicememories'], ...EntityOwnership::RELATIONS['glpi_items_devicememories'], 'devicememories_id' => 'glpi_devicememories'],
        'glpi_items_devicemotherboards' => [...OptionalReferences::RELATIONS['glpi_items_devicemotherboards'], ...EntityOwnership::RELATIONS['glpi_items_devicemotherboards'], 'devicemotherboards_id' => 'glpi_devicemotherboards'],
        'glpi_items_devicenetworkcards' => [...OptionalReferences::RELATIONS['glpi_items_devicenetworkcards'], ...EntityOwnership::RELATIONS['glpi_items_devicenetworkcards'], 'devicenetworkcards_id' => 'glpi_devicenetworkcards'],
        'glpi_items_devicepcis' => [...OptionalReferences::RELATIONS['glpi_items_devicepcis'], ...EntityOwnership::RELATIONS['glpi_items_devicepcis'], 'devicepcis_id' => 'glpi_devicepcis'],
        'glpi_items_devicepowersupplies' => [...OptionalReferences::RELATIONS['glpi_items_devicepowersupplies'], ...EntityOwnership::RELATIONS['glpi_items_devicepowersupplies'], 'devicepowersupplies_id' => 'glpi_devicepowersupplies'],
        'glpi_items_deviceprocessors' => [...OptionalReferences::RELATIONS['glpi_items_deviceprocessors'], ...EntityOwnership::RELATIONS['glpi_items_deviceprocessors'], 'deviceprocessors_id' => 'glpi_deviceprocessors'],
        'glpi_items_devicesensors' => [...OptionalReferences::RELATIONS['glpi_items_devicesensors'], ...EntityOwnership::RELATIONS['glpi_items_devicesensors'], 'devicesensors_id' => 'glpi_devicesensors'],
        'glpi_items_devicesimcards' => [...OptionalReferences::RELATIONS['glpi_items_devicesimcards'], ...EntityOwnership::RELATIONS['glpi_items_devicesimcards'], 'devicesimcards_id' => 'glpi_devicesimcards'],
        'glpi_items_devicesoundcards' => [...OptionalReferences::RELATIONS['glpi_items_devicesoundcards'], ...EntityOwnership::RELATIONS['glpi_items_devicesoundcards'], 'devicesoundcards_id' => 'glpi_devicesoundcards'],
        'glpi_items_disks' => [...OptionalReferences::RELATIONS['glpi_items_disks'], ...EntityOwnership::RELATIONS['glpi_items_disks']],
        'glpi_items_enclosures' => ['enclosures_id' => 'glpi_enclosures'],
        'glpi_items_operatingsystems' => [...OptionalReferences::RELATIONS['glpi_items_operatingsystems'], ...EntityOwnership::RELATIONS['glpi_items_operatingsystems']],
        'glpi_items_problems' => ['problems_id' => 'glpi_problems'],
        'glpi_items_projects' => ['projects_id' => 'glpi_projects'],
        'glpi_items_racks' => ['racks_id' => 'glpi_racks'],
        'glpi_items_softwarelicenses' => ['softwarelicenses_id' => 'glpi_softwarelicenses'],
        'glpi_items_softwareversions' => [...EntityOwnership::RELATIONS['glpi_items_softwareversions'], 'softwareversions_id' => 'glpi_softwareversions'],
        'glpi_items_tickets' => ['tickets_id' => 'glpi_tickets'],
        'glpi_itilcategories' => [...OptionalReferences::RELATIONS['glpi_itilcategories'], ...EntityOwnership::RELATIONS['glpi_itilcategories']],
        'glpi_itils_projects' => ['projects_id' => 'glpi_projects'],
        'glpi_knowbaseitems_comments' => [...OptionalReferences::RELATIONS['glpi_knowbaseitems_comments'], 'knowbaseitems_id' => 'glpi_knowbaseitems', 'parent_comment_id' => 'glpi_knowbaseitems_comments'],
        'glpi_knowbaseitems_items' => ['knowbaseitems_id' => 'glpi_knowbaseitems'],
        'glpi_knowbaseitems_profiles' => ['knowbaseitems_id' => 'glpi_knowbaseitems', 'profiles_id' => 'glpi_profiles'],
        'glpi_knowbaseitems_revisions' => [...OptionalReferences::RELATIONS['glpi_knowbaseitems_revisions'], 'knowbaseitems_id' => 'glpi_knowbaseitems'],
        'glpi_knowbaseitems_users' => ['knowbaseitems_id' => 'glpi_knowbaseitems', 'users_id' => 'glpi_users'],
        'glpi_knowbaseitemtranslations' => [...OptionalReferences::RELATIONS['glpi_knowbaseitemtranslations'], 'knowbaseitems_id' => 'glpi_knowbaseitems'],
        'glpi_lines' => [...OptionalReferences::RELATIONS['glpi_lines'], ...EntityOwnership::RELATIONS['glpi_lines']],
        'glpi_monitors' => [...OptionalReferences::RELATIONS['glpi_monitors'], ...EntityOwnership::RELATIONS['glpi_monitors']],
        'glpi_netpoints' => [...OptionalReferences::RELATIONS['glpi_netpoints'], ...EntityOwnership::RELATIONS['glpi_netpoints']],
        'glpi_networkequipments' => [...OptionalReferences::RELATIONS['glpi_networkequipments'], ...EntityOwnership::RELATIONS['glpi_networkequipments']],
        'glpi_profiles' => OptionalReferences::RELATIONS['glpi_profiles'],
        'glpi_ticketrecurrents' => [...OptionalReferences::RELATIONS['glpi_ticketrecurrents'], ...EntityOwnership::RELATIONS['glpi_ticketrecurrents']],
        'glpi_networknames' => [...OptionalReferences::NETWORK_NAMES['glpi_networknames'], ...EntityOwnership::RELATIONS['glpi_networknames']],
        'glpi_networkaliases' => [...OptionalReferences::NETWORK_NAMES['glpi_networkaliases'], ...EntityOwnership::RELATIONS['glpi_networkaliases'], 'networknames_id' => 'glpi_networknames'],
        'glpi_networkportaggregates' => ['networkports_id' => 'glpi_networkports'],
        'glpi_networkportaliases' => [...OptionalReferences::NETWORK_PORT_METADATA['glpi_networkportaliases'], 'networkports_id' => 'glpi_networkports'],
        'glpi_networkportdialups' => ['networkports_id' => 'glpi_networkports'],
        'glpi_networkportethernets' => [...OptionalReferences::NETWORK_PORT_METADATA['glpi_networkportethernets'], 'networkports_id' => 'glpi_networkports'],
        'glpi_networkportfiberchannels' => [...OptionalReferences::NETWORK_PORT_METADATA['glpi_networkportfiberchannels'], 'networkports_id' => 'glpi_networkports'],
        'glpi_networkportlocals' => ['networkports_id' => 'glpi_networkports'],
        'glpi_networkportwifis' => [...OptionalReferences::NETWORK_PORT_METADATA['glpi_networkportwifis'], 'networkports_id' => 'glpi_networkports'],
        'glpi_networkports_networkports' => ['networkports_id_1' => 'glpi_networkports', 'networkports_id_2' => 'glpi_networkports'],
        'glpi_networkports_vlans' => ['networkports_id' => 'glpi_networkports', 'vlans_id' => 'glpi_vlans'],
        'glpi_notifications_notificationtemplates' => ['notifications_id' => 'glpi_notifications', 'notificationtemplates_id' => 'glpi_notificationtemplates'],
        'glpi_notificationtargets' => ['notifications_id' => 'glpi_notifications'],
        'glpi_notificationtemplatetranslations' => ['notificationtemplates_id' => 'glpi_notificationtemplates'],
        'glpi_operatingsystemkernelversions' => [...OptionalReferences::RELATIONS['glpi_operatingsystemkernelversions']],
        'glpi_passivedcequipments' => [...OptionalReferences::RELATIONS['glpi_passivedcequipments'], ...EntityOwnership::RELATIONS['glpi_passivedcequipments']],
        'glpi_pdus' => [...OptionalReferences::RELATIONS['glpi_pdus'], ...EntityOwnership::RELATIONS['glpi_pdus']],
        'glpi_pdus_plugs' => ['pdus_id' => 'glpi_pdus', 'plugs_id' => 'glpi_plugs'],
        'glpi_pdus_racks' => ['pdus_id' => 'glpi_pdus', 'racks_id' => 'glpi_racks'],
        'glpi_peripherals' => [...OptionalReferences::RELATIONS['glpi_peripherals'], ...EntityOwnership::RELATIONS['glpi_peripherals']],
        'glpi_phones' => [...OptionalReferences::RELATIONS['glpi_phones'], ...EntityOwnership::RELATIONS['glpi_phones']],
        'glpi_planningexternalevents' => [...OptionalReferences::RELATIONS['glpi_planningexternalevents'], ...EntityOwnership::RELATIONS['glpi_planningexternalevents']],
        'glpi_planningexternaleventtemplates' => [...OptionalReferences::RELATIONS['glpi_planningexternaleventtemplates'], ...EntityOwnership::RELATIONS['glpi_planningexternaleventtemplates']],
        'glpi_printers' => [...OptionalReferences::RELATIONS['glpi_printers'], ...EntityOwnership::RELATIONS['glpi_printers']],
        'glpi_problemcosts' => [...OptionalReferences::RELATIONS['glpi_problemcosts'], ...EntityOwnership::RELATIONS['glpi_problemcosts'], 'problems_id' => 'glpi_problems'],
        'glpi_problems_suppliers' => [...OptionalReferences::RELATIONS['glpi_problems_suppliers'], 'problems_id' => 'glpi_problems'],
        'glpi_problems_tickets' => ['problems_id' => 'glpi_problems', 'tickets_id' => 'glpi_tickets'],
        'glpi_problems_users' => [...OptionalReferences::RELATIONS['glpi_problems_users'], 'problems_id' => 'glpi_problems'],
        'glpi_problemtasks' => [...OptionalReferences::RELATIONS['glpi_problemtasks'], 'problems_id' => 'glpi_problems'],
        'glpi_problemtemplatehiddenfields' => ['problemtemplates_id' => 'glpi_problemtemplates'],
        'glpi_problemtemplatemandatoryfields' => ['problemtemplates_id' => 'glpi_problemtemplates'],
        'glpi_problemtemplatepredefinedfields' => ['problemtemplates_id' => 'glpi_problemtemplates'],
        'glpi_profilerights' => ['profiles_id' => 'glpi_profiles'],
        'glpi_profiles_reminders' => ['reminders_id' => 'glpi_reminders', 'profiles_id' => 'glpi_profiles'],
        'glpi_profiles_rssfeeds' => ['rssfeeds_id' => 'glpi_rssfeeds', 'profiles_id' => 'glpi_profiles'],
        'glpi_profiles_users' => ['users_id' => 'glpi_users', 'profiles_id' => 'glpi_profiles', 'entities_id' => 'glpi_entities'],
        'glpi_projectcosts' => [...OptionalReferences::RELATIONS['glpi_projectcosts'], ...EntityOwnership::RELATIONS['glpi_projectcosts'], 'projects_id' => 'glpi_projects'],
        'glpi_projects' => [...OptionalReferences::RELATIONS['glpi_projects'], ...EntityOwnership::RELATIONS['glpi_projects']],
        'glpi_projecttasks' => [...OptionalReferences::RELATIONS['glpi_projecttasks'], ...EntityOwnership::RELATIONS['glpi_projecttasks']],
        'glpi_projecttasks_tickets' => ['projecttasks_id' => 'glpi_projecttasks', 'tickets_id' => 'glpi_tickets'],
        'glpi_projecttaskteams' => ['projecttasks_id' => 'glpi_projecttasks'],
        'glpi_projecttasktemplates' => [...OptionalReferences::RELATIONS['glpi_projecttasktemplates'], ...EntityOwnership::RELATIONS['glpi_projecttasktemplates']],
        'glpi_projectteams' => ['projects_id' => 'glpi_projects'],
        'glpi_queuedchats' => [...OptionalReferences::RELATIONS['glpi_queuedchats'], ...EntityOwnership::RELATIONS['glpi_queuedchats']],
        'glpi_racks' => [...OptionalReferences::RELATIONS['glpi_racks'], ...EntityOwnership::RELATIONS['glpi_racks']],
        'glpi_reminders_users' => ['reminders_id' => 'glpi_reminders', 'users_id' => 'glpi_users'],
        'glpi_remindertranslations' => ['reminders_id' => 'glpi_reminders'],
        'glpi_reservations' => [...OptionalReferences::RELATIONS['glpi_reservations'], 'reservationitems_id' => 'glpi_reservationitems'],
        'glpi_rssfeeds_users' => ['rssfeeds_id' => 'glpi_rssfeeds', 'users_id' => 'glpi_users'],
        'glpi_ruleactions' => ['rules_id' => 'glpi_rules'],
        'glpi_rulecriterias' => ['rules_id' => 'glpi_rules'],
        'glpi_savedsearches_alerts' => ['savedsearches_id' => 'glpi_savedsearches'],
        'glpi_savedsearches_users' => ['savedsearches_id' => 'glpi_savedsearches', 'users_id' => 'glpi_users'],
        'glpi_softwarelicenses' => [...OptionalReferences::RELATIONS['glpi_softwarelicenses'], ...EntityOwnership::RELATIONS['glpi_softwarelicenses'], 'softwares_id' => 'glpi_softwares'],
        'glpi_softwares' => [...OptionalReferences::RELATIONS['glpi_softwares'], ...EntityOwnership::RELATIONS['glpi_softwares']],
        'glpi_softwareversions' => [...OptionalReferences::RELATIONS['glpi_softwareversions'], ...EntityOwnership::RELATIONS['glpi_softwareversions'], 'softwares_id' => 'glpi_softwares'],
        'glpi_suppliers' => [...OptionalReferences::RELATIONS['glpi_suppliers'], ...EntityOwnership::RELATIONS['glpi_suppliers']],
        'glpi_suppliers_tickets' => [...OptionalReferences::RELATIONS['glpi_suppliers_tickets'], 'tickets_id' => 'glpi_tickets'],
        'glpi_tasktemplates' => [...OptionalReferences::RELATIONS['glpi_tasktemplates'], ...EntityOwnership::RELATIONS['glpi_tasktemplates']],
        'glpi_ticketcosts' => [...OptionalReferences::RELATIONS['glpi_ticketcosts'], ...EntityOwnership::RELATIONS['glpi_ticketcosts'], 'tickets_id' => 'glpi_tickets'],
        'glpi_tickets' => [...OptionalReferences::RELATIONS['glpi_tickets'], ...EntityOwnership::RELATIONS['glpi_tickets']],
        'glpi_tickets_tickets' => ['tickets_id_1' => 'glpi_tickets', 'tickets_id_2' => 'glpi_tickets'],
        'glpi_tickets_users' => [...OptionalReferences::RELATIONS['glpi_tickets_users'], 'tickets_id' => 'glpi_tickets'],
        'glpi_ticketsatisfactions' => ['tickets_id' => 'glpi_tickets'],
        'glpi_tickettasks' => [...OptionalReferences::RELATIONS['glpi_tickettasks'], 'tickets_id' => 'glpi_tickets'],
        'glpi_tickettemplatehiddenfields' => ['tickettemplates_id' => 'glpi_tickettemplates'],
        'glpi_tickettemplatemandatoryfields' => ['tickettemplates_id' => 'glpi_tickettemplates'],
        'glpi_tickettemplatepredefinedfields' => ['tickettemplates_id' => 'glpi_tickettemplates'],
        'glpi_ticketvalidations' => [...OptionalReferences::RELATIONS['glpi_ticketvalidations'], ...EntityOwnership::RELATIONS['glpi_ticketvalidations'], 'tickets_id' => 'glpi_tickets'],
        'glpi_useremails' => ['users_id' => 'glpi_users'],
        'glpi_users' => [...OptionalReferences::RELATIONS['glpi_users'], ...EntityOwnership::RELATIONS['glpi_users']],
        'glpi_changes' => [...OptionalReferences::RELATIONS['glpi_changes'], ...EntityOwnership::RELATIONS['glpi_changes']],
        'glpi_problems' => [...OptionalReferences::RELATIONS['glpi_problems'], ...EntityOwnership::RELATIONS['glpi_problems']],
        'glpi_taskcategories' => [...OptionalReferences::RELATIONS['glpi_taskcategories'], ...EntityOwnership::RELATIONS['glpi_taskcategories']],
        'glpi_itilfollowups' => [...OptionalReferences::RELATIONS['glpi_itilfollowups']],
        'glpi_itilfollowuptemplates' => [...OptionalReferences::RELATIONS['glpi_itilfollowuptemplates'], ...EntityOwnership::RELATIONS['glpi_itilfollowuptemplates']],
        'glpi_itilsolutions' => [...OptionalReferences::RELATIONS['glpi_itilsolutions']],
        'glpi_solutiontemplates' => [...OptionalReferences::RELATIONS['glpi_solutiontemplates'], ...EntityOwnership::RELATIONS['glpi_solutiontemplates']],
        'glpi_businesscriticities' => [...OptionalReferences::RELATIONS['glpi_businesscriticities'], ...EntityOwnership::RELATIONS['glpi_businesscriticities']],
        'glpi_documentcategories' => [...OptionalReferences::RELATIONS['glpi_documentcategories']],
        'glpi_knowbaseitemcategories' => [...OptionalReferences::RELATIONS['glpi_knowbaseitemcategories'], ...EntityOwnership::RELATIONS['glpi_knowbaseitemcategories']],
        'glpi_locations' => [...OptionalReferences::RELATIONS['glpi_locations'], ...EntityOwnership::RELATIONS['glpi_locations']],
        'glpi_softwarecategories' => [...OptionalReferences::RELATIONS['glpi_softwarecategories']],
        'glpi_softwarelicensetypes' => [...OptionalReferences::RELATIONS['glpi_softwarelicensetypes'], ...EntityOwnership::RELATIONS['glpi_softwarelicensetypes']],
        'glpi_states' => [...OptionalReferences::RELATIONS['glpi_states'], ...EntityOwnership::RELATIONS['glpi_states']],
    ];

    public static function name(string $table, string $column): string
    {
        return 'fk_' . substr($table, 5) . '_' . $column;
    }

    public function addToSchema(Schema $schema): void
    {
        foreach (self::RELATIONS as $table => $relations) {
            foreach ($relations as $column => $parent) {
                $child = $schema->getTable($table);
                $child->addForeignKeyConstraint($parent, [$column], ['id'], ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'], self::name($table, $column));
            }
        }
    }

    /** Return orphan counts; no application data is ever repaired or deleted. */
    public function audit(Connection $connection): array
    {
        $problems = [];
        $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
        foreach (self::RELATIONS as $table => $relations) {
            foreach ($relations as $column => $parent) {
                $count = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($table) . ' c LEFT JOIN ' . $quote($parent) . ' p ON c.' . $quote($column) . ' = p.id WHERE c.' . $quote($column) . ' IS NOT NULL AND p.id IS NULL');
                if ($count > 0) {
                    $problems[$table . '.' . $column] = $count;
                }
            }
        }
        return $problems;
    }

    public function plan(Connection $connection): array
    {
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $sql = [];
        foreach (self::RELATIONS as $table => $relations) {
            $existing = $manager->listTableForeignKeys($table);
            foreach ($relations as $column => $parent) {
                $name = self::name($table, $column);
                foreach ($existing as $constraint) {
                    if ($constraint->getName() === $name) {
                        if ($constraint->getLocalColumns() !== [$column] || $constraint->getForeignTableName() !== $parent || $constraint->getForeignColumns() !== ['id'] || !in_array($constraint->onDelete(), [null, 'RESTRICT', 'NO ACTION'], true) || !in_array($constraint->onUpdate(), [null, 'RESTRICT', 'NO ACTION'], true)) {
                            throw new \RuntimeException('Existing foreign key has a different definition: ' . $name);
                        }
                        continue 2;
                    }
                }
                $foreignKey = new ForeignKeyConstraint([$column], $parent, ['id'], $name, ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT']);
                $sql[] = $platform->getCreateForeignKeySQL($foreignKey, $table);
            }
        }
        return $sql;
    }

    public function apply(Connection $connection): void
    {
        $problems = $this->audit($connection);
        if ($problems) {
            throw new \RuntimeException('Foreign keys were not installed; orphaned references: ' . json_encode($problems));
        }
        // MySQL ALTER TABLE commits implicitly; each statement is idempotent on
        // retry. PostgreSQL callers can wrap the whole install in a transaction.
        foreach ($this->plan($connection) as $sql) {
            $connection->executeStatement($sql);
        }
    }
}
