<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen 20261001 upgrade for the six core planning recall subjects. */
final class PlanningRecallSubjects extends TypedItemMigration
{
    public const VERSION = '20261001_planning_recall_subjects';

    protected function tables(): array
    {
        return ['glpi_planningrecalls'];
    }

    protected static function targets(): array
    {
        return ['ChangeTask' => 'changetasks', 'ProblemTask' => 'problemtasks', 'Reminder' => 'reminders',
            'TicketTask' => 'tickettasks', 'ProjectTask' => 'projecttasks', 'PlanningExternalEvent' => 'planningexternalevents'];
    }
}
