<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\Frozen\InitialOwnersV1;

/** Frozen final parent declarations for the first ORM release. */
final class NetworkPort extends InitialOwnerAdoption
{
    public const TABLE = 'glpi_networkports';
    public const CHECK = 'glpi_networkports_parent_kind';
    public const OWNERS = [
        'Computer' => ['computers_id', 'glpi_computers', 'glpi_networkports_computers_id', 'glpi_networkports_computers_id'],
        'Monitor' => ['monitors_id', 'glpi_monitors', 'glpi_networkports_monitors_id', 'glpi_networkports_monitors_id'],
        'NetworkEquipment' => ['networkequipments_id', 'glpi_networkequipments', 'glpi_networkports_networkequipments_id', 'glpi_networkports_networkequipments_id'],
        'Peripheral' => ['peripherals_id', 'glpi_peripherals', 'glpi_networkports_peripherals_id', 'glpi_networkports_peripherals_id'],
        'Phone' => ['phones_id', 'glpi_phones', 'glpi_networkports_phones_id', 'glpi_networkports_phones_id'],
        'Printer' => ['printers_id', 'glpi_printers', 'glpi_networkports_printers_id', 'glpi_networkports_printers_id'],
        'Enclosure' => ['enclosures_id', 'glpi_enclosures', 'glpi_networkports_enclosures_id', 'glpi_networkports_enclosures_id'],
        'PDU' => ['pdus_id', 'glpi_pdus', 'glpi_networkports_pdus_id', 'glpi_networkports_pdus_id'],
        'Cluster' => ['clusters_id', 'glpi_clusters', 'glpi_networkports_clusters_id', 'glpi_networkports_clusters_id'],
    ];
}
