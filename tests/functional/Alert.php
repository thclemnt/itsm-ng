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

namespace tests\units;

use DbTestCase;

/* Test for inc/alert.class.php */

class Alert extends DbTestCase
{
    public function testAddDelete()
    {
        $alert = new \Alert();
        $nb    = (int)countElementsInTable($alert->getTable());
        $subject = new \Contract();
        $subject_id = $subject->add([
           'name'        => 'alert-end-contract-' . $this->getUniqueString(),
           'entities_id' => 0,
           'begin_date'  => '2015-09-01',
           'duration'    => 12,
           'notice'      => 2,
        ]);
        $this->integer((int)$subject_id)->isGreaterThan(0);
        $this->boolean($subject->getFromDB($subject_id))->isTrue();
        $date  = '2016-09-01 12:34:56';

        // Add
        $id = $alert->add([
           'itemtype' => $subject->getType(),
           'items_id' => $subject->getID(),
           'type'     => \Alert::END,
           'date'     => $date,
        ]);
        $this->integer($id)->isGreaterThan(0);
        $this->integer((int)countElementsInTable($alert->getTable()))->isGreaterThan($nb);

        // Getters
        $this->boolean(\Alert::alertExists($subject->getType(), $subject->getID(), \Alert::NOTICE))->isFalse();
        $this->integer((int)\Alert::alertExists($subject->getType(), $subject->getID(), \Alert::END))->isIdenticalTo($id);
        $this->string(\Alert::getAlertDate($subject->getType(), $subject->getID(), \Alert::END))->isIdenticalTo($date);

        // Display
        $this->output(
            function () use ($subject) {
                \Alert::displayLastAlert($subject->getType(), $subject->getID());
            }
        )->isIdenticalTo(sprintf('Alert sent on %s', \Html::convDateTime($date)));

        // Delete
        $this->boolean($alert->clear($subject->getType(), $subject->getID(), \Alert::END))->isTrue();
        $this->integer((int)countElementsInTable($alert->getTable()))->isIdenticalTo($nb);

        // Still true, nothing to delete but no error
        $this->boolean($alert->clear($subject->getType(), $subject->getID(), \Alert::END))->isTrue();
    }
}
