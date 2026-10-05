<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen 20261001 upgrade for the four supported project/team member kinds. */
final class ProjectTeamMembers extends TypedItemMigration
{
    protected function tables(): array
    {
        return ['glpi_projectteams', 'glpi_projecttaskteams'];
    }

    protected static function targets(): array
    {
        return ['User' => 'users', 'Group' => 'groups', 'Supplier' => 'suppliers', 'Contact' => 'contacts'];
    }
}
