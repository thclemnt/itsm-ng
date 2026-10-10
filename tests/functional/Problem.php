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
use DbUtils;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ITILTicketLinkRepository;
use Problem_Ticket;
use ReflectionMethod;

/* Test for inc/problem.class.php */

class Problem extends DbTestCase
{
    public function testAddFromItem()
    {
        // add problem from a computer
        $computer   = getItemByTypeName('Computer', '_test_pc01');
        $problem     = new \Problem();
        $problems_id = $problem->add([
           'name'           => "test add from computer \'_test_pc01\'",
           'content'        => "test add from computer \'_test_pc01\'",
           '_add_from_item' => true,
           '_from_itemtype' => 'Computer',
           '_from_items_id' => $computer->getID(),
        ]);
        $this->integer($problems_id)->isGreaterThan(0);
        $this->boolean($problem->getFromDB($problems_id))->isTrue();

        // check relation
        $problem_item = new \Item_Problem();
        $this->boolean($problem_item->getFromDBForItems($problem, $computer))->isTrue();
    }

    public function testAssignedStatusOnAddWithAssignee()
    {
        $this->login();

        $users_id_assign = (int)getItemByTypeName('User', 'tech', true);
        $problem = new \Problem();
        $problems_id = $problem->add([
           'name'              => 'problem auto assigned',
           'content'           => 'assignment status should switch',
           'status'            => \Problem::INCOMING,
           '_users_id_assign'  => $users_id_assign,
        ]);
        $this->integer($problems_id)->isGreaterThan(0);
        $this->boolean($problem->getFromDB($problems_id))->isTrue();
        $this->integer((int)$problem->fields['status'])->isEqualTo(\Problem::ASSIGNED);
    }

    public function testAddIgnoresNewItemPlaceholderId()
    {
        $this->login();

        $problem = new \Problem();
        $problems_id = $problem->add([
           'id'      => -1,
           'name'    => 'problem created from form placeholder id',
           'content' => 'id placeholder must not be stored as the real primary key',
        ]);

        $this->integer((int)$problems_id)->isGreaterThan(0);
        $this->integer((int)$problems_id)->isNotEqualTo(-1);
        $this->boolean($problem->getFromDB($problems_id))->isTrue();
        $this->integer((int)$problem->fields['id'])->isEqualTo((int)$problems_id);
    }

    public function testReopenViaFollowup()
    {
        $this->login();

        $problem = new \Problem();
        $problems_id = $problem->add([
           'name'    => 'problem to reopen',
           'content' => 'initial content',
        ]);
        $this->integer($problems_id)->isGreaterThan(0);

        $this->boolean(
            $problem->update([
               'id'      => $problems_id,
               'status'  => \Problem::SOLVED,
            ])
        )->isTrue();
        $this->boolean($problem->getFromDB($problems_id))->isTrue();
        $this->integer((int)$problem->fields['status'])->isEqualTo(\Problem::SOLVED);

        $interface_bak = $_SESSION['glpiactiveprofile']['interface'] ?? null;
        $_SESSION['glpiactiveprofile']['interface'] = 'helpdesk';

        $followup = new \ITILFollowup();
        $followups_id = $followup->add([
           'itemtype'    => 'Problem',
           'items_id'    => $problems_id,
           'content'     => 'Need to reopen after review',
           'add_reopen'  => 1,
        ]);
        $this->integer($followups_id)->isGreaterThan(0);

        if ($interface_bak === null) {
            unset($_SESSION['glpiactiveprofile']['interface']);
        } else {
            $_SESSION['glpiactiveprofile']['interface'] = $interface_bak;
        }

        $this->boolean($problem->getFromDB($problems_id))->isTrue();
        $this->integer((int)$problem->fields['status'])->isEqualTo(\Problem::INCOMING);
    }

    public function testProblemTaskActiontimeAndPrivateFlag()
    {
        $this->login();

        $problem = new \Problem();
        $problems_id = $problem->add([
           'name'    => 'problem with task actiontime',
           'content' => 'validate actiontime update from ProblemTask',
        ]);
        $this->integer($problems_id)->isGreaterThan(0);

        $task = new \ProblemTask();
        $task_id = $task->add([
           'problems_id'      => $problems_id,
           'content'          => 'private problem task',
           'actiontime'       => 240,
           'is_private'       => 1,
           'users_id_tech'    => getItemByTypeName('User', 'tech', true),
        ]);
        $this->integer((int)$task_id)->isGreaterThan(0);
        $this->boolean($task->getFromDB($task_id))->isTrue();
        $this->integer((int)$task->fields['is_private'])->isEqualTo(1);

        $this->boolean($problem->getFromDB($problems_id))->isTrue();
        $this->integer((int)$problem->fields['actiontime'])->isEqualTo(240);

        $this->boolean($task->delete(['id' => $task_id]))->isTrue();
        $this->boolean($problem->getFromDB($problems_id))->isTrue();
        $this->integer((int)$problem->fields['actiontime'])->isEqualTo(0);
    }

    public function testPrepareInputForAddTranslatesItilAssignPayload()
    {
        $this->login();

        $problem = new \Problem();
        $users_id_assign = (int)getItemByTypeName('User', 'tech', true);
        $input = $problem->prepareInputForAdd([
           'name'         => 'problem actor panel add',
           'content'      => 'validate shared actor panel payload on add',
           '_itil_assign' => [
              '_type'            => 'user',
              'users_id'         => $users_id_assign,
              'use_notification' => ['1'],
              'alternative_email' => [''],
           ],
        ]);

        $this->array($input)->hasKey('_users_id_assign');
        $this->integer((int)$input['_users_id_assign'])->isEqualTo($users_id_assign);
    }

    public function testLinkedTicketProjectionPreservesVisibility(): void
    {
        global $DB;
        $this->login();
        $problem = $this->createItem('Problem', ['name' => 'Linked problem ' . $this->getUniqueString(), 'content' => 'Endpoint projection']);
        $ticket = $this->createItem('Ticket', ['name' => 'Linked problem ticket ' . $this->getUniqueString(), 'content' => 'Endpoint projection']);
        $link = $this->createItem('Problem_Ticket', ['problems_id' => $problem->getID(), 'tickets_id' => $ticket->getID()]);
        $this->boolean($problem->canViewItem())->isTrue();
        $this->boolean($ticket->canViewItem())->isTrue();
        $scope = (new DbUtils())->getEntityRestriction('glpi_tickets', '', '', 'auto');
        $read = static fn (): array => Orm::read($DB, static fn (EntityManager $manager): array =>
            (new ITILTicketLinkRepository($manager))->ticketsForProblem((int)$problem->getID(), $scope));
        $rows = $read();
        $this->integer(count($rows))->isIdenticalTo(1);
        $this->integer((int)$rows[0]['id'])->isIdenticalTo((int)$ticket->getID());
        $this->integer((int)$rows[0]['linkid'])->isIdenticalTo((int)$link->getID());
        $this->integer((int)$rows[0]['entity'])->isIdenticalTo((int)$ticket->getEntityID());
        $reverseScope = (new DbUtils())->getEntityRestriction('glpi_problems', '', '', 'auto');
        $reverse = Orm::read($DB, static fn (EntityManager $manager): array =>
            (new ITILTicketLinkRepository($manager))->problemsForTicket((int)$ticket->getID(), $reverseScope));
        $this->integer(count($reverse))->isIdenticalTo(1);
        $this->integer((int)$reverse[0]['id'])->isIdenticalTo((int)$problem->getID());
        $this->variable($reverse[0]['itilcategories_id'])->isNull();
        $tickets = new ReflectionMethod(Problem_Ticket::class, 'getProblemTicketsData');
        $problems = new ReflectionMethod(Problem_Ticket::class, 'getTicketProblemsData');
        $this->integer(count($tickets->invoke(null, $problem->getID())))->isIdenticalTo(1);
        $this->integer(count($problems->invoke(null, $ticket->getID())))->isIdenticalTo(1);
        foreach ([null, 'nUlL', 0, -1] as $empty) {
            $this->array($tickets->invoke(null, $empty))->isEmpty();
            $this->array($problems->invoke(null, $empty))->isEmpty();
        }
        $this->output(static fn () => Problem_Ticket::showForProblem($problem))->contains('/front/ticket.form.php?id=' . $ticket->getID());
        $this->output(static fn () => Problem_Ticket::showForTicket($ticket))->contains('/front/problem.form.php?id=' . $problem->getID());
        $session = $_SESSION;
        try {
            $_SESSION['glpishowallentities'] = false;
            $_SESSION['glpiactiveentities'] = [];
            $this->array($tickets->invoke(null, $problem->getID()))->isEmpty();
            $this->array($problems->invoke(null, $ticket->getID()))->isEmpty();
            $this->output(static fn () => Problem_Ticket::showForProblem($problem))->isEmpty();
            $_SESSION = $session;
            $_SESSION['glpiactiveprofile']['problem'] = 0;
            $this->array($problems->invoke(null, $ticket->getID()))->isEmpty('Per-target canViewItem remains outside the projection');
        } finally {
            $_SESSION = $session;
        }
        $DB->getDoctrineConnection()->update('glpi_tickets', ['is_deleted' => true, 'name' => 'Fresh linked problem ticket'], ['id' => $ticket->getID()]);
        $visible = $tickets->invoke(null, $problem->getID());
        $this->integer(count($visible))->isIdenticalTo(1);
        $this->string($visible[$ticket->getID()]['name'])->isIdenticalTo('Fresh linked problem ticket');
        $this->string($rows[0]['name'])->isIdenticalTo($ticket->fields['name']);
        $this->boolean($link->delete(['id' => $link->getID()], true))->isTrue();
        $this->array($tickets->invoke(null, $problem->getID()))->isEmpty();
        $this->array($problems->invoke(null, $ticket->getID()))->isEmpty();
    }

}
