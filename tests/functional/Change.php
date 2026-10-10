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

use Change as ChangeModel;
use Problem as ProblemModel;
use Ticket as TicketModel;
use Change_Problem;
use Change_Ticket;
use DbTestCase;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ITILTicketLinkRepository;
use itsmng\Database\Repository\ChangeProblemRepository;

/* Test for inc/change.class.php */

class Change extends DbTestCase
{
    public function testAddFromItem()
    {
        // add change from a computer
        $computer   = getItemByTypeName('Computer', '_test_pc01');
        $change     = new \Change();
        $changes_id = $change->add([
           'name'           => "test add from computer \'_test_pc01\'",
           'content'        => "test add from computer \'_test_pc01\'",
           '_add_from_item' => true,
           '_from_itemtype' => 'Computer',
           '_from_items_id' => $computer->getID(),
        ]);
        $this->integer($changes_id)->isGreaterThan(0);
        $this->boolean($change->getFromDB($changes_id))->isTrue();

        // check relation
        $change_item = new \Change_Item();
        $this->boolean($change_item->getFromDBForItems($change, $computer))->isTrue();
    }

    public function testChangeValidationWithMultipleValidators()
    {
        $this->login();

        $change = new \Change();
        $changes_id = $change->add([
           'name'    => 'change with multi validators',
           'content' => 'validation workflow',
        ]);
        $this->integer($changes_id)->isGreaterThan(0);

        $users_id_itsm = (int)getItemByTypeName('User', 'itsm', true);
        $users_id_tech = (int)getItemByTypeName('User', 'tech', true);

        $validation = new \ChangeValidation();
        $validation_id = $validation->add([
           'changes_id'         => $changes_id,
           'users_id_validate'  => [$users_id_itsm, $users_id_tech],
           'comment_submission' => 'Please validate this change',
        ]);
        $this->integer($validation_id)->isGreaterThan(0);

        $this->integer(countElementsInTable(
            \ChangeValidation::getTable(),
            ['changes_id' => $changes_id]
        ))->isEqualTo(2);

        $this->boolean($change->getFromDB($changes_id))->isTrue();
        $this->integer((int)$change->fields['global_validation'])->isEqualTo(\CommonITILValidation::WAITING);

        $this->login('itsm', 'itsm');
        $validation = new \ChangeValidation();
        $this->boolean(
            $validation->getFromDBByCrit([
               'changes_id'         => $changes_id,
               'users_id_validate'  => $users_id_itsm,
            ])
        )->isTrue();

        $this->boolean(
            $validation->update([
               'id'                 => $validation->fields['id'],
               'changes_id'         => $changes_id,
               'status'             => \CommonITILValidation::REFUSED,
               'comment_validation' => 'Needs rework',
            ])
        )->isTrue();

        $change = new \Change();
        $this->boolean($change->getFromDB($changes_id))->isTrue();
        $this->integer((int)$change->fields['global_validation'])->isEqualTo(\CommonITILValidation::REFUSED);
    }

    public function testStatusTransitionLifecycle()
    {
        $this->login();

        $allowed_path = [
            [\Change::INCOMING, \Change::EVALUATION],
            [\Change::EVALUATION, \Change::APPROVAL],
            [\Change::APPROVAL, \Change::ACCEPTED],
            [\Change::ACCEPTED, \Change::TEST],
            [\Change::TEST, \Change::QUALIFICATION],
            [\Change::QUALIFICATION, \Change::SOLVED],
            [\Change::SOLVED, \Change::CLOSED],
        ];

        foreach ($allowed_path as [$from, $to]) {
            $this->boolean(\Change::isAllowedStatus($from, $to))->isTrue();
        }
    }

    public function testChangeTaskActiontimeAndPrivateFlag()
    {
        $this->login();

        $change = new \Change();
        $changes_id = $change->add([
           'name'    => 'change with task actiontime',
           'content' => 'validate actiontime update from ChangeTask',
        ]);
        $this->integer($changes_id)->isGreaterThan(0);

        $task = new \ChangeTask();
        $task_id = $task->add([
           'changes_id'       => $changes_id,
           'content'          => 'private task',
           'actiontime'       => 180,
           'is_private'       => 1,
           'users_id_tech'    => getItemByTypeName('User', 'tech', true),
        ]);
        $this->integer((int)$task_id)->isGreaterThan(0);
        $this->boolean($task->getFromDB($task_id))->isTrue();
        $this->integer((int)$task->fields['is_private'])->isEqualTo(1);

        $this->boolean($change->getFromDB($changes_id))->isTrue();
        $this->integer((int)$change->fields['actiontime'])->isEqualTo(180);

        $this->boolean($task->delete(['id' => $task_id]))->isTrue();
        $this->boolean($change->getFromDB($changes_id))->isTrue();
        $this->integer((int)$change->fields['actiontime'])->isEqualTo(0);
    }

    public function testPrepareInputForAddTranslatesItilRequesterPayload()
    {
        $this->login();

        $change = new \Change();
        $users_id_requester = (int)getItemByTypeName('User', 'normal', true);
        $input = $change->prepareInputForAdd([
           'name'            => 'change actor panel add',
           'content'         => 'validate shared actor panel payload on add',
           '_itil_requester' => [
              '_type'             => 'user',
              'users_id'          => $users_id_requester,
              'use_notification'  => ['1'],
              'alternative_email' => [''],
           ],
        ]);

        $this->array($input)->hasKey('_users_id_requester');
        $this->integer((int)$input['_users_id_requester'])->isEqualTo($users_id_requester);
    }

    public function testAddFromProblemCreatesRelationFromLegacyInputKey()
    {
        $this->login();

        $problem = new \Problem();
        $problems_id = $problem->add([
           'name'    => 'problem source for change relation',
           'content' => 'problem content copied to change creation flow',
        ]);
        $this->integer((int)$problems_id)->isGreaterThan(0);

        $change = new \Change();
        $changes_id = $change->add([
           'name'        => 'change linked from problem',
           'content'     => 'ensure relation is created when problems_id is used',
           'problems_id' => $problems_id,
        ]);
        $this->integer((int)$changes_id)->isGreaterThan(0);
        $this->boolean($change->getFromDB($changes_id))->isTrue();

        $change_problem = new \Change_Problem();
        $this->boolean($change_problem->getFromDBForItems($change, $problem))->isTrue();
    }

    public function testAutomaticStatusChange()
    {
        $this->login();

        $change = new \Change();
        $changes_id = $change->add([
           'name'        => 'test automatic status change',
           'content'     => 'test automatic status change',
           'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
        ]);

        $this->integer((int)$changes_id)->isGreaterThan(0);
        $this->integer((int)$change->fields['status'])->isEqualTo(\CommonITILObject::INCOMING);

        $users_id_assign = getItemByTypeName('User', TU_USER, true);
        $this->integer((int)$users_id_assign)->isGreaterThan(0);

        $this->boolean(
            $change->update([
               'id'           => $changes_id,
               '_itil_assign' => [
                  '_type'    => 'user',
                  'users_id' => $users_id_assign,
               ],
            ])
        )->isTrue();

        $this->boolean($change->getFromDB($changes_id))->isTrue();
        $change->loadActors();
        $this->integer((int)$change->countUsers(\CommonITILActor::ASSIGN))->isEqualTo(1);
        $this->integer((int)$change->fields['status'])->isEqualTo(\CommonITILObject::INCOMING);

        $this->boolean(
            $change->update([
               'id'     => $changes_id,
               'status' => \CommonITILObject::ACCEPTED,
            ])
        )->isTrue();

        $change_user = new \Change_User();
        $change_user->deleteByCriteria([
           'changes_id' => $changes_id,
           'type'       => \CommonITILActor::ASSIGN,
           'users_id'   => $users_id_assign,
        ]);

        $this->boolean($change->getFromDB($changes_id))->isTrue();
        $change->loadActors();
        $this->integer((int)$change->countUsers(\CommonITILActor::ASSIGN))->isEqualTo(0);
        $this->integer((int)$change->fields['status'])->isEqualTo(\CommonITILObject::ACCEPTED);
    }

    public function testAddAdditionalActorsDuplicated()
    {
        $this->login();

        $change = new \Change();
        $changes_id = $change->add([
           'name'    => 'test add additional actors duplicated',
           'content' => 'test add additional actors duplicated',
        ]);
        $this->integer((int)$changes_id)->isGreaterThan(0);

        $users_id = getItemByTypeName('User', TU_USER, true);

        $this->boolean(
            $change->update([
               'id'                     => $changes_id,
               '_additional_requesters' => [
                  [
                     'users_id'         => $users_id,
                     'use_notification' => 0,
                  ],
               ],
            ])
        )->isTrue();

        $this->boolean(
            $change->update([
               'id'                     => $changes_id,
               '_additional_requesters' => [
                  [
                     'users_id'         => $users_id,
                     'use_notification' => 0,
                  ],
               ],
            ])
        )->isTrue();
    }

    public function testInitialStatus()
    {
        $this->login();

        $change = new \Change();
        $changes_id = $change->add([
           'name'             => 'test initial status',
           'content'          => 'test initial status',
           'entities_id'      => getItemByTypeName('Entity', '_test_root_entity', true),
           '_users_id_assign' => getItemByTypeName('User', TU_USER, true),
        ]);

        $this->integer((int)$changes_id)->isGreaterThan(0);
        $this->integer((int)$change->fields['status'])->isEqualTo(\CommonITILObject::INCOMING);
    }

    public function testStatusWhenSolutionIsRefused()
    {
        $this->login();

        $change = new \Change();
        $changes_id = $change->add([
           'name'             => 'test status when solution is refused',
           'content'          => 'test status when solution is refused',
           'entities_id'      => getItemByTypeName('Entity', '_test_root_entity', true),
           'status'           => \CommonITILObject::SOLVED,
        ]);

        $this->integer((int)$changes_id)->isGreaterThan(0);

        $followup = new \ITILFollowup();
        $followup_id = $followup->add([
           'itemtype'        => 'Change',
           'items_id'        => $changes_id,
           'users_id'        => getItemByTypeName('User', TU_USER, true),
           'users_id_editor' => getItemByTypeName('User', TU_USER, true),
           'content'         => 'Test followup content',
           'requesttypes_id' => 1,
           'timeline_position' => \CommonITILObject::TIMELINE_LEFT,
           'add_reopen'      => '',
        ]);

        $this->integer((int)$followup_id)->isGreaterThan(0);
        $this->boolean($change->getFromDB($changes_id))->isTrue();
        $this->integer((int)$change->fields['status'])->isEqualTo(\CommonITILObject::INCOMING);
    }

    public function testShowFormNewItem()
    {
        $change = new \Change();
        $change->getEmpty();

        $this->login();

        ob_start();
        $change->showForm($change->getID());
        $html = ob_get_clean();

        $this->string((string)$html)->isNotEmpty();
    }

    public function testShowFormClosedItem()
    {
        $this->login();

        $change = new \Change();
        $change_id = $change->add([
           'name'        => 'closed change form',
           'content'     => 'closed change form',
           'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
           'status'      => \CommonITILObject::CLOSED,
        ]);
        $this->integer((int)$change_id)->isGreaterThan(0);

        ob_start();
        $change->showForm($change_id);
        $html = ob_get_clean();

        $this->string((string)$html)->isNotEmpty();
    }

    public function testCreateChangeFromUser()
    {
        $this->login();

        $users_id_requester = \Session::getLoginUserID();

        $change = new \Change();
        $changes_id = $change->add([
           'name'                => 'Change created from the user profile',
           'content'             => 'Hello world',
           'entities_id'         => getItemByTypeName('Entity', '_test_root_entity', true),
           '_users_id_requester' => $users_id_requester,
        ]);

        $this->integer((int)$changes_id)->isGreaterThan(0);
        $this->boolean($change->getFromDB($changes_id))->isTrue();

        $change_user = new \Change_User();
        $this->integer(
            count($change_user->find([
               'changes_id' => $changes_id,
               'users_id'   => $users_id_requester,
               'type'       => \CommonITILActor::REQUESTER,
            ]))
        )->isEqualTo(1);
    }

    public function testLinkedTicketEndpointProjection(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $entityId = (int)$_SESSION['glpiactive_entity'];
        $change = $this->createItem('Change', ['entities_id' => $entityId, 'name' => 'Linked change ' . $this->getUniqueString(), 'content' => 'Endpoint projection']);
        $otherChange = $this->createItem('Change', ['entities_id' => $entityId, 'name' => 'Other change ' . $this->getUniqueString(), 'content' => 'Other endpoint']);
        $first = $this->createItem('Ticket', ['entities_id' => $entityId, 'name' => 'AAA linked ticket ' . $this->getUniqueString(), 'content' => 'First endpoint']);
        $last = $this->createItem('Ticket', ['entities_id' => $entityId, 'name' => 'ZZZ linked ticket ' . $this->getUniqueString(), 'content' => 'Last endpoint']);
        $firstLink = $this->createItem('Change_Ticket', ['changes_id' => $change->getID(), 'tickets_id' => $first->getID()]);
        $lastLink = $this->createItem('Change_Ticket', ['changes_id' => $change->getID(), 'tickets_id' => $last->getID()]);
        $this->createItem('Change_Ticket', ['changes_id' => $otherChange->getID(), 'tickets_id' => $first->getID()]);
        $connection = $DB->getDoctrineConnection();
        $connection->update('glpi_tickets', ['date_mod' => null, 'closedate' => null, 'solvedate' => null,
            'begin_waiting_date' => null, 'time_to_resolve' => null], ['id' => $first->getID()]);
        $read = static fn (): array => Orm::read($DB, static fn (EntityManager $manager): array =>
            (new ITILTicketLinkRepository($manager))->ticketsForChange((int)$change->getID()));
        $rows = $read();
        $this->array(array_map('intval', array_column($rows, 'id')))->isIdenticalTo([(int)$first->getID(), (int)$last->getID()]);
        $this->array(array_map('intval', array_column($rows, 'linkid')))->isIdenticalTo([(int)$firstLink->getID(), (int)$lastLink->getID()]);
        $this->variable($rows[0]['date_mod'])->isNull();
        $this->variable($rows[0]['closedate'])->isNull();
        $this->variable($rows[0]['solvedate'])->isNull();
        $this->variable($rows[0]['begin_waiting_date'])->isNull();
        $this->variable($rows[0]['time_to_resolve'])->isNull();
        $this->variable($rows[0]['itilcategories_id'])->isNull();
        $this->integer((int)$rows[0]['entities_id'])->isIdenticalTo((int)$first->getEntityID());
        $reverse = Orm::read($DB, static fn (EntityManager $manager): array =>
            (new ITILTicketLinkRepository($manager))->changesForTicket((int)$first->getID()));
        $this->integer(count($reverse))->isIdenticalTo(2);
        $this->integer((int)$reverse[0]['id'])->isIdenticalTo((int)$change->getID());
        $this->integer((int)$reverse[1]['id'])->isIdenticalTo((int)$otherChange->getID());
        $this->array(array_map('intval', array_column($reverse, 'linkid')))->contains((int)$firstLink->getID());
        foreach ([null, 0, -1] as $empty) {
            $this->array(Orm::read($DB, static fn (EntityManager $manager): array =>
                (new ITILTicketLinkRepository($manager))->ticketsForChange($empty)))->isEmpty();
        }
        $rendered = $this->renderLocalTableRows(static fn () => Change_Ticket::showForChange($change));
        $this->integer(count($rendered))->isIdenticalTo(2);
        $this->string($rendered[0][7])->contains(TicketModel::getFormURLWithID($first->getID()))->contains($first->getField('name'));
        $rendered = $this->renderLocalTableRows(static fn () => Change_Ticket::showForTicket($first));
        $this->integer(count($rendered))->isIdenticalTo(2);
        $this->string($rendered[0][7])->contains(ChangeModel::getFormURLWithID($change->getID()))->contains($change->getField('name'));
        $freshName = 'BBB fresh linked ticket ' . $this->getUniqueString();
        $connection->update('glpi_tickets', ['name' => $freshName, 'date_mod' => '2021-02-03 04:05:06', 'is_deleted' => true], ['id' => $first->getID()]);
        $fresh = $read();
        $this->string($fresh[0]['name'])->isIdenticalTo($freshName);
        $this->string($fresh[0]['date_mod'])->isIdenticalTo('2021-02-03 04:05:06');
        $this->variable($rows[0]['date_mod'])->isNull();
        $this->integer(count($fresh))->isIdenticalTo(2, 'Change links do not filter soft-deleted targets');
        $session = $_SESSION;
        try {
            $_SESSION['glpishowallentities'] = false;
            $_SESSION['glpiactiveentities'] = [];
            $this->integer(count($read()))->isIdenticalTo(2, 'Change projection has no entity prefilter');
            $this->output(static fn () => Change_Ticket::showForChange($change))->isEmpty();
        } finally {
            $_SESSION = $session;
        }
        $this->boolean($lastLink->delete(['id' => $lastLink->getID()], true))->isTrue();
        $this->integer(count($read()))->isIdenticalTo(1);
        $this->integer((int)$read()[0]['linkid'])->isIdenticalTo((int)$firstLink->getID());
    }


    public function testLinkedTaskPlanningUsesActualParentInRenderedTooltips(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $entityId = (int)$_SESSION['glpiactive_entity'];
        $parent = $this->createItem('Change', ['entities_id' => $entityId, 'name' => 'Planning linked parent ' . $this->getUniqueString(), 'content' => 'Planning tooltip']);
        $ticket = $this->createItem('Ticket', ['entities_id' => $entityId, 'name' => 'Planning linked ticket ' . $this->getUniqueString(), 'content' => 'Planning tooltip']);
        $this->createItem('Change_Ticket', ['changes_id' => $parent->getID(), 'tickets_id' => $ticket->getID()]);
        $parentTask = $this->createItem('ChangeTask', ['changes_id' => $parent->getID(), 'content' => 'Parent planning row']);
        $ticketTask = $this->createItem('TicketTask', ['tickets_id' => $ticket->getID(), 'content' => 'Ticket planning row']);
        $connection = $DB->getDoctrineConnection();
        foreach ([$parentTask, $ticketTask] as $task) {
            $connection->update(
                $task->getTable(),
                [
                    $connection->quoteIdentifier('begin') => '2030-02-03 04:05:06',
                    $connection->quoteIdentifier('end') => '2030-02-03 05:06:07'
                ],
                ['id' => $task->getID()]
            );
        }
        $this->output(static fn () => Change_Ticket::showForChange($parent))->contains('Ticket' . $ticket->getID() . 'planning');
        $this->output(static fn () => Change_Ticket::showForTicket($ticket))->contains('Change' . $parent->getID() . 'planning');
        $connection->update(
            $parentTask->getTable(),
            [
                $connection->quoteIdentifier('begin') => null,
                $connection->quoteIdentifier('end') => null
            ],
            ['id' => $parentTask->getID()]
        );
        $this->output(static fn () => Change_Ticket::showForTicket($ticket))->notContains('Change' . $parent->getID() . 'planning');
    }


    public function testChangeProblemTabsFollowOwningLinksAndPlanning(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $entityId = (int)$_SESSION['glpiactive_entity'];
        $problem = $this->createItem('Problem', ['entities_id' => $entityId, 'name' => 'Parent linked problem ' . $this->getUniqueString(), 'content' => 'Linked tab parent']);
        $other = $this->createItem('Problem', ['entities_id' => $entityId, 'name' => 'Other linked problem ' . $this->getUniqueString(), 'content' => 'Other linked parent']);
        $first = $this->createItem('Change', ['entities_id' => $entityId, 'name' => 'AAA linked change ' . $this->getUniqueString(), 'content' => 'First linked change']);
        $last = $this->createItem('Change', ['entities_id' => $entityId, 'name' => 'ZZZ linked change ' . $this->getUniqueString(), 'content' => 'Last linked change']);
        $firstLink = $this->createItem('Change_Problem', ['changes_id' => $first->getID(), 'problems_id' => $problem->getID()]);
        $lastLink = $this->createItem('Change_Problem', ['changes_id' => $last->getID(), 'problems_id' => $problem->getID()]);
        $otherLink = $this->createItem('Change_Problem', ['changes_id' => $first->getID(), 'problems_id' => $other->getID()]);
        $changeTask = $this->createItem('ChangeTask', ['changes_id' => $first->getID(), 'content' => 'Change tab planning']);
        $problemTask = $this->createItem('ProblemTask', ['problems_id' => $problem->getID(), 'content' => 'Problem tab planning']);
        $connection = $DB->getDoctrineConnection();
        foreach ([$changeTask, $problemTask] as $task) {
            $connection->update(
                $task->getTable(),
                [
                    $connection->quoteIdentifier('begin') => '2030-02-03 04:05:06',
                    $connection->quoteIdentifier('end') => '2030-02-03 05:06:07'
                ],
                ['id' => $task->getID()]
            );
        }
        $connection->update('glpi_changes', ['date_mod' => null, 'closedate' => null, 'solvedate' => null,
            'begin_waiting_date' => null, 'time_to_resolve' => null], ['id' => $first->getID()]);
        $read = static fn (): array => Orm::read($DB, static fn (EntityManager $manager): array =>
            (new ChangeProblemRepository($manager))->changesForProblem((int)$problem->getID()));
        $rows = $read();
        $this->array(array_map('intval', array_column($rows, 'id')))->isIdenticalTo([(int)$first->getID(), (int)$last->getID()]);
        $this->array(array_map('intval', array_column($rows, 'linkid')))->isIdenticalTo([(int)$firstLink->getID(), (int)$lastLink->getID()]);
        foreach (['date_mod', 'closedate', 'solvedate', 'begin_waiting_date', 'time_to_resolve'] as $calendar) {
            $this->variable($rows[0][$calendar])->isNull();
        }
        $reverse = Orm::read($DB, static fn (EntityManager $manager): array =>
            (new ChangeProblemRepository($manager))->problemsForChange((int)$first->getID()));
        $this->integer(count($reverse))->isIdenticalTo(2);
        $this->integer((int)$reverse[0]['id'])->isIdenticalTo((int)$other->getID());
        $this->integer((int)$reverse[1]['id'])->isIdenticalTo((int)$problem->getID());
        foreach ([null, 0, -1] as $empty) {
            $this->array(Orm::read($DB, static fn (EntityManager $manager): array =>
                (new ChangeProblemRepository($manager))->changesForProblem($empty)))->isEmpty();
            $this->array(Orm::read($DB, static fn (EntityManager $manager): array =>
                (new ChangeProblemRepository($manager))->problemsForChange($empty)))->isEmpty();
        }
        $rendered = $this->renderLocalTableRows(static fn () => Change_Problem::showForProblem($problem));
        $this->integer(count($rendered))->isIdenticalTo(2);
        $this->string($rendered[0][7])->contains(ChangeModel::getFormURLWithID($first->getID()))->contains($first->getField('name'));
        $this->string($rendered[0][8])->contains('Change' . $first->getID() . 'planning');
        $rendered = $this->renderLocalTableRows(static fn () => Change_Problem::showForChange($first));
        $this->integer(count($rendered))->isIdenticalTo(2);
        $this->string($rendered[1][7])->contains(ProblemModel::getFormURLWithID($problem->getID()))->contains($problem->getField('name'));
        $this->string($rendered[1][8])->contains('Problem' . $problem->getID() . 'planning');
        $session = $_SESSION;
        try {
            $_SESSION['glpiactiveprofile']['problem'] = 0;
            $this->output(static fn () => Change_Problem::showForChange($first))->isEmpty();
            $_SESSION = $session;
            $_SESSION['glpiactiveentities'] = [];
            $this->integer(count($read()))->isIdenticalTo(2, 'The endpoint projection does not add an entity prefilter');
            $this->output(static fn () => Change_Problem::showForProblem($problem))->isEmpty();
            $this->output(static fn () => Change_Problem::showForChange($first))->isEmpty();
        } finally {
            $_SESSION = $session;
        }
        $freshName = 'BBB fresh linked change ' . $this->getUniqueString();
        $connection->update('glpi_changes', ['name' => $freshName, 'date_mod' => '2021-02-03 04:05:06', 'is_deleted' => true], ['id' => $first->getID()]);
        $fresh = $read();
        $this->integer(count($fresh))->isIdenticalTo(2, 'Linked soft-deleted targets remain projected');
        $this->string($fresh[0]['name'])->isIdenticalTo($freshName);
        $this->string($fresh[0]['date_mod'])->isIdenticalTo('2021-02-03 04:05:06');
        $this->variable($rows[0]['date_mod'])->isNull();
        $this->boolean($lastLink->delete(['id' => $lastLink->getID()], true))->isTrue();
        $this->integer(count($read()))->isIdenticalTo(1);
        $this->boolean($problem->delete(['id' => $problem->getID()], true))->isTrue();
        $this->array($read())->isEmpty();
        $surviving = Orm::read($DB, static fn (EntityManager $manager): array =>
            (new ChangeProblemRepository($manager))->problemsForChange((int)$first->getID()));
        $this->integer(count($surviving))->isIdenticalTo(1);
        $this->integer((int)$surviving[0]['linkid'])->isIdenticalTo((int)$otherLink->getID());
    }

}
