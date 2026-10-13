<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\ComponentParents;

use itsmng\Database\Migration\V220\OpenParentAdoption;

/** Final 2.2.0 ownership, copied directly from the released stored identity. */
final class NetworkCardDefinition extends OpenParentAdoption
{
    public const PHASE = '2.2.0.items_devicenetworkcards.parents';
    public const TABLE = 'glpi_items_devicenetworkcards';
    protected const CHECK = 'glpi_items_devicenetworkcards_parent_kind';
    protected const LABEL = 'NetworkCard component';
    protected const LOWER_LABEL = 'NetworkCard component';
    protected const PARENT_LABEL = 'NetworkCard component parents';
    protected const DISCRIMINATOR_NULLABLE = true;
    protected const DISCRIMINATOR_LENGTH = 255;
    protected const OWNERS = [
        'Computer' => ['computers_id', 'glpi_computers', 'glpi_items_devicenetworkcards_computer_owner', 'fk_items_devicenetworkcards_computers_id'],
        'NetworkEquipment' => ['networkequipments_id', 'glpi_networkequipments', 'glpi_items_devicenetworkcards_networkequipment_owner', 'fk_items_devicenetworkcards_networkequipments_id'],
        'Peripheral' => ['peripherals_id', 'glpi_peripherals', 'glpi_items_devicenetworkcards_peripheral_owner', 'fk_items_devicenetworkcards_peripherals_id'],
        'Phone' => ['phones_id', 'glpi_phones', 'glpi_items_devicenetworkcards_phone_owner', 'fk_items_devicenetworkcards_phones_id'],
        'Printer' => ['printers_id', 'glpi_printers', 'glpi_items_devicenetworkcards_printer_owner', 'fk_items_devicenetworkcards_printers_id'],
    ];
}
