<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\NotepadParents;

use itsmng\Database\Migration\V220\OpenParentAdoption;

/** Final 2.2.0 ownership, copied directly from the released stored identity. */
final class Definition extends OpenParentAdoption
{
    public const PHASE = '2.2.0.notepads.parents';
    public const TABLE = 'glpi_notepads';
    protected const CHECK = 'glpi_notepads_parent_kind';
    protected const LABEL = 'Notepad';
    protected const LOWER_LABEL = 'Notepad';
    protected const PARENT_LABEL = 'note parents';
    protected const DISCRIMINATOR_NULLABLE = true;
    protected const DISCRIMINATOR_LENGTH = 100;
    protected const OWNERS = [
        'Computer' => ['computers_id', 'glpi_computers', 'glpi_notepads_computers_id', 'fk_notepads_computers_id'],
    ];
}
