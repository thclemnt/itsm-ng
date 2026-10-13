<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\ComponentParents;

use itsmng\Database\Migration\V220\OpenParentAdoption;

/** Final 2.2.0 ownership, copied directly from the released stored identity. */
final class ControlDefinition extends OpenParentAdoption
{
    public const PHASE = '2.2.0.items_devicecontrols.parents';
    public const TABLE = 'glpi_items_devicecontrols';
    protected const CHECK = 'glpi_items_devicecontrols_parent_kind';
    protected const LABEL = 'Control component';
    protected const LOWER_LABEL = 'Control component';
    protected const PARENT_LABEL = 'Control component parents';
    protected const DISCRIMINATOR_NULLABLE = true;
    protected const DISCRIMINATOR_LENGTH = 255;
    protected const OWNERS = [
        'Computer' => ['computers_id', 'glpi_computers', 'glpi_items_devicecontrols_computer_owner', 'fk_items_devicecontrols_computers_id'],
    ];
}
