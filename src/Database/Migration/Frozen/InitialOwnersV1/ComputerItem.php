<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\Frozen\InitialOwnersV1;

/** Proposed initial table facts, pending application declaration qualification. */
final class ComputerItem extends InitialOwnerAdoption
{
    public const TABLE = 'glpi_computers_items';
    public const CHECK = 'glpi_computers_items_parent_kind';
    public const OWNERS = [
        'Monitor' => ['monitors_id', 'glpi_monitors', 'glpi_computers_items_monitor_owner', 'glpi_computers_items_monitor_owner'],
        'Peripheral' => ['peripherals_id', 'glpi_peripherals', 'glpi_computers_items_peripheral_owner', 'glpi_computers_items_peripheral_owner'],
        'Phone' => ['phones_id', 'glpi_phones', 'glpi_computers_items_phone_owner', 'glpi_computers_items_phone_owner'],
        'Printer' => ['printers_id', 'glpi_printers', 'glpi_computers_items_printer_owner', 'glpi_computers_items_printer_owner'],
    ];
}
