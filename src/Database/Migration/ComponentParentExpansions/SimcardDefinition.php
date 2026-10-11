<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\ComponentParentExpansions;

use itsmng\Database\Migration\ComponentParents\SimcardDefinition as ComputerOnlyDefinition;
use itsmng\Database\Migration\Frozen\ComponentExpansionV1\OpenParentExpansion;

/** Immutable declarations for this one actual component family. */
final class SimcardDefinition extends OpenParentExpansion
{
    public const TABLE = 'glpi_items_devicesimcards';
    public const PREDECESSOR = ComputerOnlyDefinition::class;
    public const PHASE = 'schema.component_parent_expansions.v1.simcard.ddl';
    protected const CHECK = 'glpi_items_devicesimcards_parent_kind';
    protected const BRIDGE = 'glpi_items_devicesimcards_parent_expansion';
    protected const FOREIGN_PREFIX = 'fk_items_devicesimcards_';
    protected const DISCRIMINATOR_NULLABLE = false;
    protected const DISCRIMINATOR_LENGTH = 100;
    protected const OWNERS = [
        'Computer' => ['computers_id', 'glpi_computers', 'computer'],
        'NetworkEquipment' => ['networkequipments_id', 'glpi_networkequipments', 'networkequipment'],
        'Peripheral' => ['peripherals_id', 'glpi_peripherals', 'peripheral'],
        'Phone' => ['phones_id', 'glpi_phones', 'phone'],
        'Printer' => ['printers_id', 'glpi_printers', 'printer'],
    ];
}
