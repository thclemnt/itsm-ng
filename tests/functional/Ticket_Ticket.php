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

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Entity\TicketTicket as TicketLinkEntity;
use itsmng\Database\RowIterator;
use mock\DBmysql as LinkRouteAdapter;
use RuntimeException;
use tests\fixtures\ScalarReadProbe;
use Throwable;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/ticket_ticket.class.php */

class Ticket_Ticket extends DbTestCase
{
    public function testLinkedTicketSnapshotKeepsDirectionCurrentRowsAndLiveOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $this->setEntity(0, true);
            $tickets = [];
            foreach (range(1, 3) as $unused) {
                $tickets[] = $this->createItem(LegacyTicket::class, [
                    'name' => $this->getUniqueString(), 'content' => 'Incident link snapshot', 'entities_id' => 0,
                ]);
            }
            [$center, $forward, $reverse] = $tickets;
            $links = [];
            foreach ([[$center, $forward], [$reverse, $center]] as [$left, $right]) {
                $links[] = $this->createItem(LegacyTicketLink::class, [
                    'tickets_id_1' => $left->getID(), 'tickets_id_2' => $right->getID(), 'link' => LegacyTicketLink::LINK_TO,
                ]);
            }
            $connection = $DB->getDoctrineConnection();
            $native = iterator_to_array($DB->request([
                'FROM' => LegacyTicketLink::getTable(),
                'WHERE' => ['OR' => ['tickets_id_1' => $center->getID(), 'tickets_id_2' => $center->getID()]],
            ]), false);
            $rows = LegacyTicketLink::getLinkedTicketsTo($center->getID());
            $keys = array_column($native, 'id');
            sort($keys);
            $this->array(array_keys($rows))->isIdenticalTo($keys);
            foreach ($native as $row) {
                $inverse = $row['tickets_id_1'] != $center->getID();
                $other = $row[$inverse ? 'tickets_id_1' : 'tickets_id_2'];
                $this->variable($rows[$row['id']]['tickets_id'])->isIdenticalTo($other);
                $this->variable($rows[$row['id']]['link'])->isIdenticalTo($row['link']);
                $this->boolean(isset($rows[$row['id']]['tickets_id_1']))->isIdenticalTo($inverse);
            }
            $this->string($rows[$links[0]->getID()]['url'])->contains($forward->fields['name']);
            $this->integer($connection->update('glpi_tickets', ['is_deleted' => true], ['id' => $forward->getID()], ['is_deleted' => Types::BOOLEAN]))->isIdenticalTo(1);
            $this->array(LegacyTicketLink::getLinkedTicketsTo($center->getID()))->hasKey($links[0]->getID());
            $owner = Orm::create($DB);
            $dirtyLink = $owner->find(TicketLinkEntity::class, (int)$links[0]->getID());
            $dirtyTicket = $owner->find(TicketEntity::class, (int)$reverse->getID());
            $dirtyLink->link = LegacyTicketLink::DUPLICATE_WITH;
            $dirtyTicket->name = 'Unflushed linked ticket';
            $this->integer($connection->update('glpi_tickets_tickets', ['link' => LegacyTicketLink::SON_OF], ['id' => $links[0]->getID()]))->isIdenticalTo(1);
            $this->variable(LegacyTicketLink::getLinkedTicketsTo((string)$center->getID())[$links[0]->getID()]['link'])->isEqualTo(LegacyTicketLink::SON_OF);
            $level = $connection->getTransactionNestingLevel();
            Orm::read($DB, function ($nestedOwner) use ($center, $links): void {
                $nested = $nestedOwner->find(TicketLinkEntity::class, (int)$links[0]->getID());
                $nested->link = LegacyTicketLink::LINK_TO;
                $this->variable(LegacyTicketLink::getLinkedTicketsTo($center->getID())[$links[0]->getID()]['link'])->isEqualTo(LegacyTicketLink::SON_OF);
                $this->boolean($nestedOwner->contains($nested))->isTrue();
                $this->integer($nested->link)->isIdenticalTo(LegacyTicketLink::LINK_TO);
            });
            $this->boolean($owner->contains($dirtyLink))->isTrue();
            $this->integer($dirtyLink->link)->isIdenticalTo(LegacyTicketLink::DUPLICATE_WITH);
            $this->boolean($owner->contains($dirtyTicket))->isTrue();
            $this->string($dirtyTicket->name)->isIdenticalTo('Unflushed linked ticket');
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
            $this->integer($connection->update('glpi_tickets', ['is_deleted' => false], ['id' => $forward->getID()], ['is_deleted' => Types::BOOLEAN]))->isIdenticalTo(1);
            $this->integer($connection->update('glpi_tickets_tickets', ['link' => LegacyTicketLink::LINK_TO], ['id' => $links[0]->getID()]))->isIdenticalTo(1);
            $duplicate = $this->createItem(LegacyTicketLink::class, [
                'tickets_id_1' => $center->getID(), 'tickets_id_2' => $forward->getID(), 'link' => LegacyTicketLink::DUPLICATE_WITH,
            ]);
            $this->boolean($links[0]->getFromDB($links[0]->getID()))->isFalse();
            $this->array(LegacyTicketLink::getLinkedTicketsTo($center->getID()))->hasKey($duplicate->getID())->notHasKey($links[0]->getID());
            $this->integer($connection->update('glpi_tickets', ['status' => LegacyTicket::SOLVED], ['id' => $center->getID()]))->isIdenticalTo(1);
            LegacyTicketLink::manageLinkedTicketsOnSolved($center->getID());
            $this->boolean($forward->getFromDB($forward->getID()))->isTrue();
            $this->integer($forward->fields['status'])->isIdenticalTo(LegacyTicket::SOLVED);
            $this->boolean($reverse->getFromDB($reverse->getID()))->isTrue();
            $this->integer($reverse->fields['status'])->isNotIdenticalTo(LegacyTicket::SOLVED);
        } finally {
            $_SESSION = $session;
        }
    }

    public function testLinkedTicketSnapshotPinsRouteAndRetainsNativeCriteria(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        $table = LegacyTicketLink::getTable();
        try {
            $this->login();
            $this->setEntity(0, true);
            $left = $this->createItem(LegacyTicket::class, ['name' => $this->getUniqueString(), 'content' => 'Snapshot route', 'entities_id' => 0]);
            $right = $this->createItem(LegacyTicket::class, ['name' => $this->getUniqueString(), 'content' => 'Snapshot route', 'entities_id' => 0]);
            $link = $this->createItem(LegacyTicketLink::class, [
                'tickets_id_1' => $left->getID(), 'tickets_id_2' => $right->getID(), 'link' => LegacyTicketLink::LINK_TO,
            ]);
            $connection = $original->getDoctrineConnection();
            $expected = LegacyTicketLink::getLinkedTicketsTo($left->getID());
            $selected = new class ($connection) extends ScalarReadProbe {
                public int $events = 0;
                public ?Throwable $failure = null;

                public function getEventManager(): EventManager
                {
                    ++$this->events;
                    return new EventManager();
                }

                public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
                {
                    if ($this->failure !== null) {
                        $this->queries[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
                        throw $this->failure;
                    }
                    return parent::executeQuery($sql, $params, $types, $qcp);
                }
            };
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new LinkRouteAdapter();
            $resolutions = 0;
            $changeDuringRender = false;
            $rotateAtSelection = true;
            $this->calling($adapter)->getDoctrineConnection = static function () use ($selected, $connection, $original, $link, $right, &$resolutions, &$changeDuringRender, &$rotateAtSelection): Connection {
                ++$resolutions;
                if ($resolutions === 1) {
                    if ($rotateAtSelection) {
                        $GLOBALS['DB'] = $original;
                    }
                    return $selected;
                }
                $GLOBALS['DB'] = $original;
                if ($changeDuringRender) {
                    $connection->update('glpi_tickets_tickets', ['link' => LegacyTicketLink::DUPLICATE_WITH], ['id' => $link->getID()]);
                    $connection->update('glpi_tickets', ['name' => 'Changed after link snapshot'], ['id' => $right->getID()]);
                }
                return $connection;
            };
            foreach ([null, false, 0, '0', '', []] as $empty) {
                $DB = $adapter;
                $this->variable(LegacyTicketLink::getLinkedTicketsTo($empty))->isFalse();
            }
            $this->integer($resolutions)->isIdenticalTo(0);
            $DB = $adapter;
            $this->array(LegacyTicketLink::getLinkedTicketsTo($left->getID()))->isIdenticalTo($expected);
            $this->integer($resolutions)->isIdenticalTo(1); // Rotation leaves the renderer on the original route.
            $this->array($selected->queries)->hasSize(1);
            $this->integer($selected->events)->isIdenticalTo(0);
            $resolutions = 0;
            $changeDuringRender = true;
            $rotateAtSelection = false;
            $DB = $adapter;
            $snapshot = LegacyTicketLink::getLinkedTicketsTo($left->getID());
            $this->variable($snapshot[$link->getID()]['link'])->isIdenticalTo($expected[$link->getID()]['link']);
            $this->string($snapshot[$link->getID()]['url'])->contains('Changed after link snapshot');
            $this->integer($resolutions)->isIdenticalTo(2); // Snapshot first, then actual Ticket reload callback.
            $this->variable(LegacyTicketLink::getLinkedTicketsTo($left->getID())[$link->getID()]['link'])->isEqualTo(LegacyTicketLink::DUPLICATE_WITH);
            $this->integer($selected->events)->isIdenticalTo(0);
            $failure = new class ('Refused incident link projection') extends RuntimeException implements DbalException {
            };
            $selected->failure = $failure;
            $resolutions = 0;
            $DB = $adapter;
            $this->exception(static fn () => LegacyTicketLink::getLinkedTicketsTo($left->getID()))->isIdenticalTo($failure);
            $this->integer($resolutions)->isIdenticalTo(1);
            $this->array($selected->queries)->hasSize(3);
            $this->integer($selected->events)->isIdenticalTo(0);
            $selected->failure = null;
            $DB = $original;
            $operators = LegacyTicketLink::getLinkedTicketsTo(['=', $left->getID()]);
            $this->array($operators)->hasKey($link->getID());
            $this->variable($operators[$link->getID()]['tickets_id_1'])->isIdenticalTo($left->getID());
            $stringable = new class () {
                public int $calls = 0;

                public function __toString(): string
                {
                    ++$this->calls;
                    return (string)PHP_INT_MAX;
                }
            };
            $this->array(LegacyTicketLink::getLinkedTicketsTo($stringable))->isEmpty();
            $this->integer($stringable->calls)->isIdenticalTo(2);
            $criteria = null;
            $this->calling($adapter)->request = static function ($request) use (&$criteria): RowIterator {
                $criteria = $request;
                return new RowIterator([]);
            };
            LegacyTicketLink::forceTable('custom_ticket_links');
            $resolutions = 0;
            $DB = $adapter;
            $this->array(LegacyTicketLink::getLinkedTicketsTo($left->getID()))->isEmpty();
            $this->string($criteria['FROM'])->isIdenticalTo('custom_ticket_links');
            $this->variable($criteria['WHERE']['OR']['tickets_id_1'])->isIdenticalTo($left->getID());
            $this->integer($resolutions)->isIdenticalTo(0);
        } finally {
            LegacyTicketLink::forceTable($table);
            $DB = $original;
            $_SESSION = $session;
        }
    }

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
