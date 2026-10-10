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
use Ticket as LegacyTicket;
use Ticket_Ticket as LegacyTicketLink;
use itsmng\Database\Entity\Ticket as TicketEntity;
use itsmng\Database\Orm;

/* Test for inc/ticket_ticket.class.php */

class Ticket_Ticket extends DbTestCase
{
    private $tone;
    private $ttwo;

    private function createTickets()
    {
        $tone = new \Ticket();
        $this->integer(
            (int)$tone->add([
              'name'         => 'Linked ticket 01',
              'description'  => 'Linked ticket 01',
              'content'      => 'Linked ticket 01',
         ])
        )->isGreaterThan(0);
        $this->boolean($tone->getFromDB($tone->getID()))->isTrue();
        $this->tone = $tone;

        $ttwo = new \Ticket();
        $this->integer(
            (int)$ttwo->add([
              'name'         => 'Linked ticket 02',
              'description'  => 'Linked ticket 02',
              'content'      => 'Linked ticket 02',
         ])
        )->isGreaterThan(0);
        $this->boolean($ttwo->getFromDB($ttwo->getID()))->isTrue();
        $this->ttwo = $ttwo;
    }

    public function testSimpleLink()
    {
        $this->createTickets();
        $tone = $this->tone;
        $ttwo = $this->ttwo;

        $link = new \Ticket_Ticket();
        $lid = (int)$link->add([
           'tickets_id_1' => $tone->getID(),
           'tickets_id_2' => $ttwo->getID(),
           'link'         => \Ticket_Ticket::LINK_TO
        ]);
        $this->integer($lid)->isGreaterThan(0);

        //cannot add same link twice!
        $this->integer(
            (int)$link->add([
              'tickets_id_1' => $tone->getID(),
              'tickets_id_2' => $ttwo->getID(),
              'link'         => \Ticket_Ticket::LINK_TO
         ])
        )->isIdenticalTo(0);

        //but can be reclassed as a duplicate
        $this->integer(
            (int)$link->add([
              'tickets_id_1' => $tone->getID(),
              'tickets_id_2' => $ttwo->getID(),
              'link'         => \Ticket_Ticket::DUPLICATE_WITH
         ])
        )->isGreaterThan(0);
        //original link has been removed
        $this->boolean($link->getFromDB($lid))->isFalse();

        //cannot eclass from duplicate to simple link
        $this->integer(
            (int)$link->add([
              'tickets_id_1' => $tone->getID(),
              'tickets_id_2' => $ttwo->getID(),
              'link'         => \Ticket_Ticket::LINK_TO
         ])
        )->isIdenticalTo(0);
    }

    public function testSonsParents()
    {
        $this->createTickets();
        $tone = $this->tone;
        $ttwo = $this->ttwo;

        $link = new \Ticket_Ticket();
        $this->integer(
            (int)$link->add([
              'tickets_id_1' => $tone->getID(),
              'tickets_id_2' => $ttwo->getID(),
              'link'         => \Ticket_Ticket::SON_OF
         ])
        )->isGreaterThan(0);

        //cannot add same link twice!
        $link = new \Ticket_Ticket();
        $this->integer(
            (int)$link->add([
              'tickets_id_1' => $tone->getID(),
              'tickets_id_2' => $ttwo->getID(),
              'link'         => \Ticket_Ticket::SON_OF
         ])
        )->isIdenticalTo(0);

        $this->createTickets();
        $tone = $this->tone;
        $ttwo = $this->ttwo;

        $link = new \Ticket_Ticket();
        $this->integer(
            (int)$link->add([
              'tickets_id_1' => $tone->getID(),
              'tickets_id_2' => $ttwo->getID(),
              'link'         => \Ticket_Ticket::PARENT_OF
         ])
        )->isGreaterThan(0);
        $this->boolean($link->getFromDB($link->getID()))->isTrue();

        //PARENT_OF is stored as inversed child
        $this->array($link->fields)
           ->integer['tickets_id_1']->isIdenticalTo($ttwo->getID())
           ->integer['tickets_id_2']->isIdenticalTo($tone->getID())
           ->integer['link']->isEqualTo(\Ticket_Ticket::SON_OF);
    }

    public function testNumberOpen()
    {
        global $DB;
        $this->login();
        $this->createTickets();
        $tone = $this->tone;
        $ttwo = $this->ttwo;

        $link = new LegacyTicketLink();
        $this->integer(
            (int)$link->add([
              'tickets_id_1' => $tone->getID(),
              'tickets_id_2' => $ttwo->getID(),
              'link'         => LegacyTicketLink::LINK_TO
         ])
        )->isGreaterThan(0);

        // A simple link does not qualify as a directional child.
        $this->integer($link->countOpenChildren($ttwo->getID()))->isIdenticalTo(0);
        $this->boolean($link->update([
            'id' => $link->getID(), 'link' => LegacyTicketLink::SON_OF,
        ]))->isTrue();
        $this->integer($link->countOpenChildren($ttwo->getID()))->isIdenticalTo(1);
        $this->integer($link->countOpenChildren($tone->getID()))->isIdenticalTo(0);
        foreach ([null, 'nUlL', 0, -1] as $parent) {
            $this->integer($link->countOpenChildren($parent))->isIdenticalTo(0);
        }

        $this->boolean($tone->update([
            'id' => $tone->getID(), 'status' => LegacyTicket::CLOSED,
        ]))->isTrue();
        $this->integer($link->countOpenChildren($ttwo->getID()))->isIdenticalTo(0);

        $savedSession = $_SESSION;
        $writer = Orm::create($DB);
        try {
            $managed = $writer->find(TicketEntity::class, (int)$tone->getID());
            $oldStatus = $managed->status;
            $connection = $DB->getDoctrineConnection();
            $this->integer($connection->update('glpi_tickets', ['status' => LegacyTicket::SOLVED], ['id' => $tone->getID()]))->isIdenticalTo(1);
            // The historic status union excludes CLOSED only; SOLVED still warns.
            $this->integer($link->countOpenChildren($ttwo->getID()))->isIdenticalTo(1);
            $_SESSION['glpiactiveentities'] = [];
            $this->integer($connection->update('glpi_tickets', ['is_deleted' => true], ['id' => $tone->getID()]))->isIdenticalTo(1);
            $this->integer($link->countOpenChildren($ttwo->getID()))->isIdenticalTo(1);
            $this->boolean($writer->contains($managed))->isTrue();
            $this->integer($managed->status)->isIdenticalTo($oldStatus);
            $this->boolean($managed->is_deleted)->isFalse();
            $this->integer($connection->update('glpi_tickets', ['status' => LegacyTicket::CLOSED], ['id' => $tone->getID()]))->isIdenticalTo(1);
            $this->integer($link->countOpenChildren($ttwo->getID()))->isIdenticalTo(0);
            $this->boolean($writer->contains($managed))->isTrue();
            $this->integer($managed->status)->isIdenticalTo($oldStatus);
        } finally {
            $writer->clear();
            $_SESSION = $savedSession;
        }
    }
}
