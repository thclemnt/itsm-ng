<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\ComponentParents;

use itsmng\Database\Migration\V220\OpenParentAdoption;

/** Final 2.2.0 ownership, copied directly from the released stored identity. */
final class SimcardDefinition extends OpenParentAdoption
{
    public const PHASE = '2.2.0.items_devicesimcards.parents';
    public const TABLE = 'glpi_items_devicesimcards';
    protected const CHECK = 'glpi_items_devicesimcards_parent_kind';
    protected const LABEL = 'Simcard component';
    protected const LOWER_LABEL = 'Simcard component';
    protected const PARENT_LABEL = 'Simcard component parents';
    protected const OWNERS = [
        'Computer' => ['computers_id', 'glpi_computers', 'glpi_items_devicesimcards_computer_owner', 'fk_items_devicesimcards_computers_id'],
        'NetworkEquipment' => ['networkequipments_id', 'glpi_networkequipments', 'glpi_items_devicesimcards_networkequipment_owner', 'fk_items_devicesimcards_networkequipments_id'],
        'Peripheral' => ['peripherals_id', 'glpi_peripherals', 'glpi_items_devicesimcards_peripheral_owner', 'fk_items_devicesimcards_peripherals_id'],
        'Phone' => ['phones_id', 'glpi_phones', 'glpi_items_devicesimcards_phone_owner', 'fk_items_devicesimcards_phones_id'],
        'Printer' => ['printers_id', 'glpi_printers', 'glpi_items_devicesimcards_printer_owner', 'fk_items_devicesimcards_printers_id'],
    ];
}
