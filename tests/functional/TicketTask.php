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

/* Test for inc/tickettask.class.php */

class TicketTask extends DbTestCase
{
    public function centralDisplayProvider(): array
    {
        return [['TicketTask', 'Ticket', 'tickets', 'Ticket'], ['ProblemTask', 'Problem', 'problems', 'ProblemTask']];
    }

    /** @dataProvider centralDisplayProvider */
    public function testCentralDisplayRetainsSelectionAndRenderedRows(string $type, string $parentType, string $relation, string $tab): void
    {
        global $DB;
        $session = $_SESSION;
        $writer = null;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $outside = (int)$_SESSION['glpiactive_entity'];
            $entity = $this->createItem(\Entity::class, ['name' => $this->getUniqueString(), 'entities_id' => $outside]);
            $this->setEntity((int)$entity->getID(), false);
            $writer = \itsmng\Database\Orm::create($DB);
            $scopeEntity = $writer->getReference(\itsmng\Database\Entity\Entity::class, (int)$entity->getID());
            $user = (int)\Session::getLoginUserID();
            $group = new \itsmng\Database\Entity\Group();
            $group->name = $this->getUniqueString();
            $group->entities = $scopeEntity;
            $writer->persist($group);
            $parents = $tasks = [];
            $parentClass = 'itsmng\\Database\\Entity\\' . $parentType;
            $taskClass = 'itsmng\\Database\\Entity\\' . $type;
            foreach (range(0, 7) as $index) {
                $parent = new $parentClass();
                $parent->name = 'Parent ' . $index;
                $parent->entities = $index === 3
                    ? $writer->getReference(\itsmng\Database\Entity\Entity::class, $outside) : $scopeEntity;
                $parent->status = $index === 4 ? \CommonITILObject::CLOSED : \CommonITILObject::INCOMING;
                $parent->is_deleted = $index === 2;
                $parent->priority = 3;
                $writer->persist($parent);
                $task = new $taskClass();
                $task->$relation = $parent;
                $task->content = '&lt;b&gt;Task ' . $index . '&lt;/b&gt; &amp; detail';
                $task->technician = $index === 6 ? null : $writer->getReference(\itsmng\Database\Entity\User::class, $user);
                $task->groups_tech = $index === 7 ? null : $group;
                $task->state = $index === 5 ? \Planning::DONE : \Planning::TODO;
                $task->is_private = $index === 1;
                $task->date_mod = $index === 7 ? null : new \DateTimeImmutable('2030-01-0' . ($index + 1) . ' 12:00:00');
                $writer->persist($task);
                $parents[] = $parent;
                $tasks[] = $task;
            }
            $writer->flush();
            $ids = static fn (array $indexes): array => array_map(static fn (int $index): int => $tasks[$index]->id, $indexes);
            $_SESSION['glpigroups'] = [$group->id];
            $_SESSION['glpidisplay_count_on_home'] = 2;
            $_SESSION['glpipriority_3'] = '#123456';
            $scope = getEntitiesRestrictCriteria($parentType::getTable());
            $statuses = $parentType::getNotSolvedStatusArray();
            $reader = new class($DB->getDoctrineConnection(), \itsmng\Database\Orm::configuration($DB->getDoctrineConnection()->getDatabasePlatform())) extends \Doctrine\ORM\EntityManager {
                public int $queries = 0;
                public function createQuery(string $dql = ''): \Doctrine\ORM\Query
                {
                    ++$this->queries;
                    return parent::createQuery($dql);
                }
            };
            $repository = new \itsmng\Database\Repository\ITILTaskRepository($reader);
            $page = $repository->centralList($type, $statuses, true, $user, null, $scope, 0);
            $rows = $page['rows'];
            $this->integer($page['total'])->isIdenticalTo(4);
            $this->integer($reader->queries)->isIdenticalTo(2);
            $this->array($reader->getUnitOfWork()->getIdentityMap())->isEmpty();
            // Deleted parents and private tasks remain included by the established task-list policy.
            $this->array(array_column($rows, 'id'))->isIdenticalTo($ids([2, 1, 0, 7]));
            $this->array(array_column($type::getTaskList('todo', false), 'id'))->isIdenticalTo($ids([2, 1, 0, 7]));
            $this->array(array_column($type::getTaskList('todo', false, 1, 2), 'id'))->isIdenticalTo($ids([1, 0]));
            $limited = $repository->centralList($type, $statuses, true, $user, null, $scope, 2);
            $this->integer($limited['total'])->isIdenticalTo(4);
            $this->array(array_column($limited['rows'], 'id'))->isIdenticalTo($ids([2, 1]));
            $this->array(array_column($repository->centralList($type, $statuses, true, $user, [$group->id], $scope, 0)['rows'], 'id'))->isIdenticalTo($ids([6, 2, 1, 0]));
            $this->array(array_column($repository->centralList($type, $statuses, false, $user, [$group->id], $scope, 0)['rows'], 'id'))->isIdenticalTo($ids([6, 5, 2, 1, 0]));
            $this->array($repository->centralList($type, $statuses, true, $user, [], $scope, 0))->isIdenticalTo(['total' => 0, 'rows' => []]);
            $this->array($repository->centralList($type, $statuses, true, 0, null, $scope, 0))->isIdenticalTo(['total' => 0, 'rows' => []]);
            $render = function (bool $groups = false, ?string $displayType = null, string $status = 'todo') use ($type): array {
                $displayType ??= $type;
                ob_start();
                try {
                    // The historical homepage entrypoint ignores its start argument.
                    $displayType::showCentralList(99, $status, $groups);
                    $html = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                if (!preg_match('/<script type="application\/json"[^>]*>(.*?)<\/script>/s', $html, $match)) {
                    return [$html, []];
                }
                return [$html, json_decode($match[1], true, 512, JSON_THROW_ON_ERROR)['dataSource']['rows']];
            };
            [$html, $rendered] = $render();
            $this->array($rendered)->hasSize(2);
            foreach ([2, 1] as $position => $index) {
                $id = sprintf(__('%1$s: %2$s'), __('ID'), $tasks[$index]->id);
                $content = \Toolbox::unclean_cross_side_scripting_deep(html_entity_decode($tasks[$index]->content, ENT_QUOTES, 'UTF-8'));
                $this->array($rendered[$position])->isIdenticalTo([
                    "<div class='priority_block' style='border-color: #123456'>\n                  <span style='background: #123456'></span>&nbsp;$id</div>",
                    $parents[$index]->name,
                    "<a href='" . $parentType::getFormURLWithID($parents[$index]->id) . "&amp;forcetab=" . $tab . "$1'> " . \Html::resume_text(\Html::Clean($content), 50),
                ]);
            }
            $this->string($html)->contains(\Html::makeTitle($type === 'TicketTask' ? __('Ticket tasks to do') : __('Problem tasks to do'), 2, 4));
            $this->boolean($DB->update($parentType::getTable(), ['name' => 'Current parent title'], ['id' => $parents[2]->id]))->isTrue();
            [, $fresh] = $render();
            $this->string($fresh[0][1])->isIdenticalTo('Current parent title');
            $this->string($parents[2]->name)->isIdenticalTo('Parent 2');
            [, $groupRows] = $render(true);
            $this->string($groupRows[0][1])->isIdenticalTo('Parent 6');
            $_SESSION['glpigroups'] = [];
            [$emptyHtml, $emptyRows] = $render(true);
            $this->string($emptyHtml)->isEmpty();
            $this->array($emptyRows)->isEmpty();
            if ($type === 'TicketTask') {
                $custom = new class extends \TicketTask {
                    public static int $loads = 0;
                    public static function getType() { return 'TicketTask'; }
                    public static function getTable($classname = null) { return \TicketTask::getTable(); }
                    public function getFromDB($id)
                    {
                        ++self::$loads;
                        $found = parent::getFromDB($id);
                        $this->fields['content'] = 'Custom task reader';
                        return $found;
                    }
                };
                [, $customRows] = $render(false, $custom::class, 'all');
                $this->integer($custom::$loads)->isIdenticalTo(2);
                $this->string($customRows[0][2])->contains('Custom task reader');
            }
        } finally {
            $writer?->clear();
            $_SESSION = $session;
        }
    }

    /**
     * Create a new ticket
     *
     * @param boolean $as_object Return Ticket object or its id
     *
     * @return integer|Ticket
     */
    private function getNewTicket($as_object = false)
    {
        //create reference ticket
        $ticket = new \Ticket();
        $this->integer(
            (int)$ticket->add([
              'name'               => 'ticket title',
              'description'        => 'a description',
              'content'            => 'a description',
              'entities_id'        => getItemByTypeName('Entity', '_test_root_entity', true),
              '_users_id_assign'   => getItemByTypeName('User', 'tech', true)
         ])
        )->isGreaterThan(0);

        $this->boolean($ticket->isNewItem())->isFalse();
        $tid = (int)$ticket->fields['id'];

        $this->hasSessionMessages(
            INFO,
            [
              "Your ticket has been registered. (Ticket: <a href='".\Ticket::getFormURLWithID($tid)."'>$tid</a>)"
         ]
        );

        return ($as_object ? $ticket : $tid);
    }

    public function testSchedulingAndRecall()
    {
        $this->login();
        $ticketId = $this->getNewTicket();
        $uid = getItemByTypeName('User', TU_USER, true);

        $date_begin = new \DateTime(); // ==> now
        $date_begin_string = $date_begin->format('Y-m-d H:i:s');

        $date_end = new \DateTime(); // ==> +2days
        $date_end->add(new \DateInterval('P2D'));
        $date_end_string = $date_end->format('Y-m-d H:i:s');

        //create one task with schedule and recall
        $task = new \TicketTask();
        $task_id = $task->add([
           'state'              => \Planning::TODO,
           'tickets_id'         => $ticketId,
           'tasktemplates_id'   => '0',
           'taskcategories_id'  => '0',
           'content'            => "Task with schedule and recall",
           'users_id_tech'      => $uid,
           'plan'               => [
              'begin'        => $date_begin_string,
              'end'          => $date_end_string,
           ],
           '_planningrecall'    => ['before_time' => '14400', //recall 4 hours
                                    'itemtype'    => 'TicketTask',
                                    'users_id'    => $uid,
                                    'field'       => 'begin', //default
                                   ]
        ]);
        $this->integer($task_id)->isGreaterThan(0);

        //load plannig schedule with recall
        $recall = new \PlanningRecall();

        //calcul 'when'
        $when = date("Y-m-d H:i:s", strtotime((string) $task->fields['begin']) - 14400);
        $this->boolean($recall->getFromDBByCrit(['before_time'   => '14400', //recall 4 hours
                                                  'itemtype'     => 'TicketTask',
                                                  'items_id'     => $task_id,
                                                  'users_id'     => $uid,
                                                  'when'         => $when,
                                               ]))->isTrue();

        //create one task with schedule and without recall
        $date_begin = new \DateTime();
        $date_begin->add(new \DateInterval('P1M'));
        $date_begin_string = $date_begin->format('Y-m-d H:i:s');

        $date_begin = new \DateTime();
        $date_end->add(new \DateInterval('P1M2D'));
        $date_end_string = $date_end->format('Y-m-d H:i:s');

        $task = new \TicketTask();
        $task_id = $task->add([
           'state'              => \Planning::TODO,
           'tickets_id'         => $ticketId,
           'tasktemplates_id'   => '0',
           'taskcategories_id'  => '0',
           'content'            => "Task with schedule and without recall",
           'users_id_tech'      => $uid,
           'plan'               => [
              'begin'       => $date_begin_string,
              'end'         => $date_end_string,
           ],
           '_planningrecall' => ['before_time' => '-10', //recall to none
                                 'itemtype'    => 'TicketTask',
                                 'users_id'    => $uid,
                                 'field'       => 'begin', //default
                                ]
        ]);
        $this->integer($task_id)->isGreaterThan(0);

        //load schedule //which return false (not exist yet without recall)
        $recall = new \PlanningRecall();
        $this->boolean($recall->getFromDBByCrit(['itemtype'   => 'TicketTask',
                                                  'items_id'  => $task_id,
                                                  'users_id'  => $uid,
                                               ]))->isFalse();

        //update task schedule with recall
        $this->boolean(
            $task->update([
         'id'                 => $task_id,
         'state'              => \Planning::TODO,
         'tickets_id'         => $ticketId,
         'tasktemplates_id'   => '0',
         'taskcategories_id'  => '0',
         'content'            => "Task with schedule and without recall",
         'users_id_tech'      => $uid,
         'plan'               => [
              'begin'       => $date_begin_string,
              'end'         => $date_end_string,
         ],
         '_planningrecall' => ['before_time' => '900',
                                 'itemtype'    => 'TicketTask',
                                 'users_id'    => $uid,
                                 'field'       => 'begin', //default
                                ]
      ])
        )->isTrue();

        //load planning recall
        $recall = new \PlanningRecall();

        //calcul when
        $when = date("Y-m-d H:i:s", strtotime((string) $task->fields['begin']) - 900);
        $this->boolean($recall->getFromDBByCrit(['before_time'  => '900',
                                                  'itemtype'    => 'TicketTask',
                                                  'items_id'    => $task_id,
                                                  'users_id'    => $uid,
                                                  'when'        => $when,
                                               ]))->isTrue();
    }

    public function testGetTaskList()
    {

        $this->login();
        $ticketId = $this->getNewTicket();
        $uid = getItemByTypeName('User', TU_USER, true);

        $tasksstates = [
           \Planning::TODO,
           \Planning::TODO,
           \Planning::INFO
        ];
        //create few tasks
        $task = new \TicketTask();
        foreach ($tasksstates as $taskstate) {
            $this->integer(
                $task->add([
                  'content'      => sprintf('Task with "%s" state', $taskstate),
                  'state'        => $taskstate,
                  'tickets_id'   => $ticketId,
                  'users_id_tech' => $uid
            ])
            )->isGreaterThan(0);
        }

        $iterator = $task::getTaskList('todo', false);
        //we create two ones plus the one in bootstrap data
        $this->integer(count($iterator))->isIdenticalTo(3);

        $iterator = $task::getTaskList('todo', true);
        $this->integer(count($iterator))->isIdenticalTo(0);

        $_SESSION['glpigroups'] = [42, 1337];
        $iterator = $task::getTaskList('todo', true);
        //no task for those groups
        $this->integer(count($iterator))->isIdenticalTo(0);
    }

    public function testCentralTaskList()
    {
        $this->login();
        $ticketId = $this->getNewTicket();
        $uid = getItemByTypeName('User', TU_USER, true);

        $tasksstates = [
           \Planning::TODO,
           \Planning::TODO,
           \Planning::TODO,
           \Planning::INFO,
           \Planning::INFO
        ];
        //create few tasks
        $task = new \TicketTask();
        foreach ($tasksstates as $taskstate) {
            $this->integer(
                $task->add([
                  'content'      => sprintf('Task with "%s" state', $taskstate),
                  'state'        => $taskstate,
                  'tickets_id'   => $ticketId,
                  'users_id_tech' => $uid
            ])
            )->isGreaterThan(0);
        }

        //How could we test there are 4 matching links?
        $this->output(
            function () {
                \TicketTask::showCentralList(0, 'todo', false);
            }
        )
           ->contains("Ticket tasks to do <span class='primary-bg primary-fg count'>4</span>")
           ->contains('ticket.form.php?id=');

        //How could we test there are 2 matching links?
        $this->output(
            function () {
                $_SESSION['glpidisplay_count_on_home'] = 2;
                \TicketTask::showCentralList(0, 'todo', false);
                unset($_SESSION['glpidisplay_count_on_home']);
            }
        )
           ->contains("Ticket tasks to do <span class='primary-bg primary-fg count'>2 on 4</span>")
           ->contains('ticket.form.php?id=');
    }

    public function testPlanningConflict()
    {
        $this->login();

        $user = getItemByTypeName('User', 'tech');
        $users_id = (int)$user->fields['id'];

        $ticket = $this->getNewTicket(true);
        $tid = $ticket->fields['id'];

        $ttask = new \TicketTask();
        $this->integer(
            (int)$ttask->add([
              'name'               => 'first test, whole period',
              'content'            => 'first test, whole period',
              'tickets_id'         => $tid,
              'plan'               => [
                 'begin'  => '2019-08-10',
                 'end'    => '2019-08-20'
              ],
              'users_id_tech'      => $users_id,
              'tasktemplates_id'   => 0
         ])
        )->isGreaterThan(0);
        $this->hasNoSessionMessages([ERROR, WARNING]);

        $this->integer(
            (int)$ttask->add([
              'name'               => 'test, subperiod',
              'content'            => 'test, subperiod',
              'tickets_id'         => $tid,
              'plan'               => [
                 'begin'   => '2019-08-13',
                 'end'     => '2019-08-14'
              ],
              'users_id_tech'      => $users_id,
              'tasktemplates_id'   => 0
         ])
        )->isGreaterThan(0);

        $usr_str = '<a href="' . $user->getFormURLWithID($users_id) . '">' . $user->getName() . '</a>';
        $this->hasSessionMessages(
            WARNING,
            [
              "The user $usr_str is busy at the selected timeframe.<br/>- Ticket task: from 2019-08-13 00:00 to 2019-08-14 00:00:<br/><a href='".
              $ticket->getFormURLWithID($tid)."&amp;forcetab=TicketTask$1'>ticket title</a><br/>"
         ]
        );
        $this->integer($tid)->isGreaterThan(0);

        //add another task to be updated
        $this->integer(
            (int)$ttask->add([
              'name'               => 'first test, whole period',
              'content'            => 'first test, whole period',
              'tickets_id'         => $tid,
              'plan'               => [
                 'begin'  => '2018-08-10',
                 'end'    => '2018-08-20'
              ],
              'users_id_tech'      => $users_id,
              'tasktemplates_id'   => 0
         ])
        )->isGreaterThan(0);
        $this->hasNoSessionMessages([ERROR, WARNING]);

        $this->boolean($ttask->getFromDB($ttask->fields['id']))->isTrue();

        $this->boolean(
            $ttask->update([
              'id'           => $ttask->fields['id'],
              'tickets_id'   => $tid,
              'plan'               => [
                 'begin'  => str_replace('2018', '2019', $ttask->fields['begin']),
                 'end'    => str_replace('2018', '2019', $ttask->fields['end'])
              ],
              'users_id_tech'      => $users_id,
         ])
        )->isTrue();

        $usr_str = '<a href="' . $user->getFormURLWithID($users_id) . '">' . $user->getName() . '</a>';
        $this->hasSessionMessages(
            WARNING,
            [
              "The user $usr_str is busy at the selected timeframe.<br/>- Ticket task: from 2019-08-10 00:00 to 2019-08-20 00:00:<br/><a href='".
              $ticket->getFormURLWithID($tid)."&amp;forcetab=TicketTask$1'>ticket title</a><br/>- Ticket task: from 2019-08-13 00:00 to 2019-08-14 00:00:<br/><a href='".$ticket
              ->getFormURLWithID($tid)."&amp;forcetab=TicketTask$1'>ticket title</a><br/>"
         ]
        );
    }
}
