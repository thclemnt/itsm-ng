<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen 20261001 upgrade for the six core calendar object subjects. */
final class VObjectSubjects extends TypedItemMigration
{
    protected function tables(): array
    {
        return ['glpi_vobjects'];
    }

    protected static function targets(): array
    {
        return ['ChangeTask' => 'changetasks', 'ProblemTask' => 'problemtasks', 'Reminder' => 'reminders',
            'TicketTask' => 'tickettasks', 'ProjectTask' => 'projecttasks', 'PlanningExternalEvent' => 'planningexternalevents'];
    }
}
