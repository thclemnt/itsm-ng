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

use Computer;
use DbTestCase;
use itsmng\Database\Orm;
use ReflectionProperty;
use Ticket;
use TicketCost;

class Item_Ticket extends DbTestCase
{
    public function testUpdateItemTCO()
    {
        $this->login();

        $computer = new Computer();
        $computers_id = (int)$computer->add([
           'name'        => __FUNCTION__,
           'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
        ]);
        $this->integer($computers_id)->isGreaterThan(0);

        $ticket = new Ticket();
        $tickets_id = (int)$ticket->add([
           'name'        => __FUNCTION__,
           'content'     => 'test',
           'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
           'items_id'    => ['Computer' => [$computers_id]],
        ]);
        $this->integer($tickets_id)->isGreaterThan(0);

        $ticket_cost = new TicketCost();
        $ticketcosts_id = (int)$ticket_cost->add([
           'tickets_id' => $tickets_id,
           'cost_fixed' => 100,
           'actiontime' => 30,
        ]);
        $this->integer($ticketcosts_id)->isGreaterThan(0);

        $this->boolean($computer->getFromDB($computers_id))->isTrue();
        $this->integer((int)$computer->fields['ticket_tco'])->isIdenticalTo(100);

        global $DB;
        $connection = $DB->getDoctrineConnection();
        $this->integer($ticket_cost->getTotalActionTimeForItem($tickets_id))->isIdenticalTo(30);
        $previous = $ticket_cost->getLastCostForItem($tickets_id);
        $this->integer((int)$previous['id'])->isIdenticalTo($ticketcosts_id);
        $this->integer((int)$previous['cost_fixed'])->isIdenticalTo(100);
        $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $before = $factories->getValue();
        try {
            $this->integer($connection->update('glpi_ticketcosts', ['actiontime' => 60, 'cost_fixed' => 125], ['id' => $ticketcosts_id]))
                ->isIdenticalTo(1);
            $this->integer($ticket_cost->getTotalActionTimeForItem($tickets_id))->isIdenticalTo(60);
            $current = $ticket_cost->getLastCostForItem($tickets_id);
            $this->integer((int)$current['id'])->isIdenticalTo($ticketcosts_id);
            $this->integer((int)$current['cost_fixed'])->isIdenticalTo(125);
            $this->integer((int)$previous['cost_fixed'])->isIdenticalTo(100);
            $this->variable($ticket_cost->getTotalActionTimeForItem(0))->isNull();
            $this->variable($ticket_cost->getLastCostForItem(0))->isNull();
        } finally {
            $connection->update('glpi_ticketcosts', ['actiontime' => 30, 'cost_fixed' => 100], ['id' => $ticketcosts_id]);
        }
        $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
    }
}
