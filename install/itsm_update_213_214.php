<?php

/**
 * ---------------------------------------------------------------------
 * ITSM-NG
 * Copyright (C) 2026 ITSM-NG and contributors.
 *
 * https://www.itsm-ng.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of ITSM-NG.
 *
 * ITSM-NG is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * ITSM-NG is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ITSM-NG. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

/**
 * Update ITSM-NG from 2.1.3 to 2.1.4
 *
 * @return bool for success (will die for most error)
 **/
function update213to214(): bool
{
    /** @global Migration $migration */
    global $migration;

    $task_tables = [
        'glpi_tickettasks'  => 'tickets_id',
        'glpi_problemtasks' => 'problems_id',
        'glpi_changetasks'  => 'changes_id',
    ];

    foreach ($task_tables as $table => $after) {
        $migration->addField($table, 'title', 'string', ['after' => $after]);
    }
    $migration->addField('glpi_tasktemplates', 'title', 'string', ['after' => 'name']);

    $migration->executeMigration();
    return true;
}
