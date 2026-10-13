<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\ComponentParents;

use itsmng\Database\Migration\V220\OpenParentAdoption;

/** Final 2.2.0 ownership, copied directly from the released stored identity. */
final class DriveDefinition extends OpenParentAdoption
{
    public const PHASE = '2.2.0.items_devicedrives.parents';
    public const TABLE = 'glpi_items_devicedrives';
    protected const CHECK = 'glpi_items_devicedrives_parent_kind';
    protected const LABEL = 'Drive component';
    protected const LOWER_LABEL = 'Drive component';
    protected const PARENT_LABEL = 'Drive component parents';
    protected const DISCRIMINATOR_NULLABLE = true;
    protected const DISCRIMINATOR_LENGTH = 255;
    protected const OWNERS = [
        'Computer' => ['computers_id', 'glpi_computers', 'glpi_items_devicedrives_computer_owner', 'fk_items_devicedrives_computers_id'],
    ];
}
