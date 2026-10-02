<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen 20261001 upgrade for the four supported project/team member kinds. */
final class ProjectTeamMembers extends TypedItemMigration
{
    public const VERSION = '20261001_project_team_members';

    protected function tables(): array
    {
        return ['glpi_projectteams', 'glpi_projecttaskteams'];
    }

    protected static function targets(): array
    {
        return ['User' => 'users', 'Group' => 'groups', 'Supplier' => 'suppliers', 'Contact' => 'contacts'];
    }
}
