<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\GraphicCardParents;

use itsmng\Database\Migration\V220\OpenParentAdoption;

/** Final 2.2.0 ownership, copied directly from the released stored identity. */
final class Definition extends OpenParentAdoption
{
    public const PHASE = '2.2.0.items_devicegraphiccards.parents';
    public const TABLE = 'glpi_items_devicegraphiccards';
    protected const CHECK = 'glpi_items_devicegraphiccards_parent_kind';
    protected const LABEL = 'Graphic card';
    protected const LOWER_LABEL = 'Graphic card';
    protected const PARENT_LABEL = 'graphic card parents';
    protected const DISCRIMINATOR_NULLABLE = true;
    protected const DISCRIMINATOR_LENGTH = 255;
    protected const OWNERS = [
        'Computer' => ['computers_id', 'glpi_computers', 'glpi_items_devicegraphiccards_computer_owner', 'fk_items_devicegraphiccards_computers_id'],
    ];
}
