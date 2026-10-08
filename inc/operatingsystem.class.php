<?php

/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

use itsmng\Database\Orm;
use itsmng\Database\ReferenceValues;
use itsmng\Database\Repository\OperatingSystemAssignmentRepository;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/// Class OperatingSystem
class OperatingSystem extends CommonDropdown
{
    public $can_be_translated = false;


    public static function getTypeName($nb = 0)
    {
        return _n('Operating system', 'Operating systems', $nb);
    }

    public function pre_deleteItem()
    {
        global $DB;

        if (!parent::pre_deleteItem()) {
            return false;
        }
        $replacement = ReferenceValues::normalizeLegacy('glpi_items_operatingsystems', ['operatingsystems_id' => $this->input['_replace_by'] ?? 0])['operatingsystems_id'];
        $assignments = new OperatingSystemAssignmentRepository(Orm::create($DB));
        if ($assignments->wouldMergeOperatingSystems((int)$this->getID(), $replacement === null ? null : (int)$replacement)) {
            Session::addMessageAfterRedirect(__('Cannot remove this operating system: it would merge distinct inventory assignments. Choose a different replacement.'), false, ERROR);
            return false;
        }
        return true;
    }
}
