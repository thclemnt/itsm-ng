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

use Auth;
use Closure;
use Document;
use Document_Item;
use Doctrine\Common\EventManager;
use NotificationTargetProjectTask;
use DbTestCase;
use Doctrine\ORM\Events;
use Project;
use ProjectTask as LegacyProjectTask;
use ProjectTaskTeam;
use Session;
use User;
use itsmng\Database\Entity\ProjectTask as ProjectTaskEntity;
use itsmng\Database\Entity\Document as DocumentEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ProjectTaskRepository;
use mock\DBmysql as AttachmentRouteAdapter;
use tests\fixtures\ScalarReadProbe;

require_once __DIR__ . '/../fixtures/ScalarReadProbe.php';

/* Test for inc/projecttask.class.php */

class ProjectTask extends DbTestCase
{
    public function testNotificationAttachmentsCompleteBeforeFormattingAndKeepLiveOwners(): void
    {
        global $DB;
        $database = $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)Session::getActiveEntity();
            $project = $this->createItem(Project::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
            $task = $this->createItem(LegacyProjectTask::class, [
                'name' => $this->getUniqueString(), 'projects_id' => $project->getID(), 'entities_id' => $entity,
            ]);
            $documents = [];
            foreach (['Second alphabetically', 'First alphabetically'] as $name) {
                $document = $this->createItem(Document::class, [
                    'name' => $name, 'link' => 'https://example.invalid/task-document', 'entities_id' => $entity,
                ]);
                $this->createItem(Document_Item::class, [
                    'documents_id' => $document->getID(), 'itemtype' => LegacyProjectTask::class, 'items_id' => $task->getID(),
                ]);
                $documents[] = $document;
            }
            $connection = $DB->getDoctrineConnection();
            $owner = Orm::create($DB);
            $dirty = $owner->find(DocumentEntity::class, (int)$documents[1]->getID());
            $dirty->name = 'Independent unflushed attachment';
            $target = new class ($entity, 'new', $task) extends NotificationTargetProjectTask {
                public ?Closure $onDocument = null;

                public function formatURL($usertype, $redirect)
                {
                    if (str_starts_with($redirect, 'document_') && $this->onDocument !== null) {
                        $callback = $this->onDocument;
                        $this->onDocument = null;
                        $callback();
                    }
                    return parent::formatURL($usertype, $redirect);
                }
            };
            $target->onDocument = function () use ($connection, $documents): void {
                $this->boolean($connection->isApplicationEntityManagerActive())->isFalse();
                $this->integer($connection->update('glpi_documents', ['name' => 'Written after attachment snapshot'], ['id' => $documents[1]->getID()]))->isIdenticalTo(1);
            };
            $options = ['additionnaloption' => ['usertype' => NotificationTargetProjectTask::GLPI_USER]];
            $depth = $connection->getTransactionNestingLevel();
            $data = $target->getForTemplate('new', $options);
            $ids = array_map(static fn ($document): int => (int)$document->getID(), $documents);
            $this->array(array_column($data['documents'], '##document.id##'))->isIdenticalTo($ids);
            $this->array(array_column($data['documents'], '##document.name##'))->isIdenticalTo(['Second alphabetically', 'First alphabetically']);
            $this->integer($data['##projecttask.numberofdocuments##'])->isIdenticalTo(2);
            $this->string($data['documents'][1]['##document.url##'])->contains('redirect=document_' . $ids[1]);
            $this->string($data['documents'][1]['##document.downloadurl##'])->contains('docid=' . $ids[1]);
            $this->string($data['documents'][1]['##document.weblink##'])->isIdenticalTo('https://example.invalid/task-document');
            Orm::read($DB, function ($nested) use ($ids, $target, $options): void {
                $retained = $nested->find(DocumentEntity::class, $ids[1]);
                $retained->name = 'Nested unflushed attachment';
                $current = $target->getForTemplate('new', $options);
                $this->string($current['documents'][1]['##document.name##'])->isIdenticalTo('Written after attachment snapshot');
                $this->boolean($nested->contains($retained))->isTrue();
                $this->string($retained->name)->isIdenticalTo('Nested unflushed attachment');
            });
            $selected = new class ($connection) extends ScalarReadProbe {
                public int $constructions = 0;

                public function getEventManager(): EventManager
                {
                    ++$this->constructions;
                    return new EventManager();
                }
            };
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new AttachmentRouteAdapter();
            $this->calling($adapter)->getDoctrineConnection = $selected;
            $this->calling($adapter)->getProvider = $database->getProvider();
            $DB = $adapter;
            $custom = $target->getForTemplate('new', $options);
            $this->array(array_column($custom['documents'], '##document.id##'))->isIdenticalTo($ids);
            $this->string($custom['documents'][1]['##document.name##'])->isIdenticalTo('Written after attachment snapshot');
            $this->integer($selected->constructions)->isGreaterThan(0);
            $this->array(array_filter($selected->queries, static fn (array $query): bool => str_contains($query['sql'], 'glpi_documents_items')))->isNotEmpty();
            $this->boolean($owner->contains($dirty))->isTrue();
            $this->string($dirty->name)->isIdenticalTo('Independent unflushed attachment');
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
        } finally {
            $DB = $database;
            $_SESSION = $session;
        }
    }

    public function testGanttRootProjectionKeepsRecursiveRenderingAndTeamAccess(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $session = $_SESSION;
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $connection = $DB->getDoctrineConnection();
        $em = Orm::create($DB);
        $listener = new class () {
            public int $loaded = 0;
            public function postLoad(): void
            {
                ++$this->loaded;
            }
        };
        $em->getEventManager()->addEventListener([Events::postLoad], $listener);
        try {
            $prefix = 'Gantt roots ' . bin2hex(random_bytes(6));
            $owner = $this->createItem(User::class, [
                'name' => $prefix . ' owner', 'entities_id' => $entity, 'authtype' => Auth::DB_GLPI,
            ]);
            $this->integer((int)$owner->getID())->isNotIdenticalTo((int)Session::getLoginUserID());
            $project = $this->createItem(Project::class, ['name' => $prefix, 'entities_id' => $entity]);
            $other = $this->createItem(Project::class, ['name' => $prefix . ' other', 'entities_id' => $entity]);
            $parent = $this->createItem(LegacyProjectTask::class, [
                'name' => $prefix . ' parent', 'projects_id' => $project->getID(), 'entities_id' => $entity,
                'users_id' => $owner->getID(), 'percent_done' => 25,
            ]);
            $child = $this->createItem(LegacyProjectTask::class, [
                'name' => $prefix . ' child', 'projects_id' => $project->getID(), 'projecttasks_id' => $parent->getID(),
                'entities_id' => $entity, 'users_id' => $owner->getID(),
                'plan_start_date' => '2030-01-10 09:00:00', 'plan_end_date' => '2030-01-20 17:00:00',
                'real_start_date' => '2030-01-12 09:00:00', 'real_end_date' => '2030-01-15 17:00:00',
            ]);
            $later = $this->createItem(LegacyProjectTask::class, [
                'name' => $prefix . ' later', 'projects_id' => $project->getID(), 'entities_id' => $entity,
                'users_id' => $owner->getID(), 'is_milestone' => 1, 'plan_start_date' => '2030-02-10 09:00:00',
            ]);
            $this->createItem(LegacyProjectTask::class, [
                'name' => $prefix . ' excluded', 'projects_id' => $other->getID(), 'entities_id' => $entity,
            ]);
            $this->createItem(ProjectTaskTeam::class, [
                'projecttasks_id' => $child->getID(), 'itemtype' => 'User', 'items_id' => Session::getLoginUserID(),
            ]);
            $projectless = new LegacyProjectTask();
            $this->integer((int)$projectless->add([
                'name' => $prefix . ' projectless', 'projects_id' => 0, 'entities_id' => $entity,
                'users_id' => $owner->getID(), 'plan_start_date' => '2030-01-05 09:00:00',
                'plan_end_date' => '2030-01-06 17:00:00',
            ]))->isGreaterThan(0);
            $this->boolean($projectless->getFromDB($projectless->getID()))->isTrue();
            $this->variable($projectless->fields['projects_id'])->isNull();
            $legacyRoots = static fn (int $id): array => array_map('intval', array_column(
                (new LegacyProjectTask())->find(['projects_id' => $id, 'projecttasks_id' => 0], ['plan_start_date', 'real_start_date']),
                'id'
            ));
            $repository = new ProjectTaskRepository($em);
            $roots = $repository->rootIdsForGantt((int)$project->getID());
            $this->array($roots)->isIdenticalTo($legacyRoots((int)$project->getID()))->hasSize(2);
            $this->array($repository->rootIdsForGantt(0))->isIdenticalTo($legacyRoots(0))
                ->contains((int)$projectless->getID());
            $projectlessRows = array_column(LegacyProjectTask::getDataToDisplayOnGanttForProject(0), null, 'id');
            $this->array($projectlessRows)->hasKey($projectless->getID());
            $this->string($projectlessRows[$projectless->getID()]['name'])->isIdenticalTo($prefix . ' projectless');
            $this->string($projectlessRows[$projectless->getID()]['from'])->isIdenticalTo('2030-01-05 09:00:00');
            $this->string($projectlessRows[$projectless->getID()]['link'])->notContains('<a ');
            $this->array($repository->rootIdsForGantt(PHP_INT_MAX))->isEmpty();
            $this->integer($listener->loaded)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
            $managed = $em->find(ProjectTaskEntity::class, (int)$later->getID());
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $connection->update('glpi_projecttasks', [
                'name' => $prefix . ' current', 'plan_start_date' => '2030-01-01 09:00:00',
                'plan_end_date' => '2030-01-01 09:00:00',
            ], ['id' => $later->getID()]);
            $roots = $repository->rootIdsForGantt((int)$project->getID());
            $this->array($roots)->isIdenticalTo($legacyRoots((int)$project->getID()));
            $this->string($managed->name)->isIdenticalTo($prefix . ' later');
            $this->boolean($em->contains($managed))->isTrue();
            $this->integer($listener->loaded)->isIdenticalTo(1);

            $_SESSION['glpiactiveprofile']['project'] = 0;
            $_SESSION['glpiactiveprofile'][LegacyProjectTask::$rightname] = LegacyProjectTask::READMY;
            $data = LegacyProjectTask::getDataToDisplayOnGanttForProject($project->getID());
            $order = [];
            foreach ($roots as $id) {
                $order[] = $id;
                if ($id === (int)$parent->getID()) {
                    $order[] = (int)$child->getID();
                }
            }
            $this->array(array_map('intval', array_column($data, 'id')))->isIdenticalTo($order);
            $byId = array_column($data, null, 'id');
            $this->string($byId[$parent->getID()]['from'])->isIdenticalTo('2030-01-12 09:00:00');
            $this->string($byId[$parent->getID()]['to'])->isIdenticalTo('2030-01-15 17:00:00');
            $this->integer($byId[$child->getID()]['parents'])->isIdenticalTo(1);
            $this->string($byId[$child->getID()]['link'])->contains('<a ')->contains($child->getLinkURL());
            $this->string($byId[$parent->getID()]['link'])->notContains('<a ');
            $this->string($byId[$later->getID()]['name'])->isIdenticalTo($prefix . ' current');
            $this->string($byId[$later->getID()]['percent'])->isIdenticalTo('');
            $this->array(LegacyProjectTask::getDataToDisplayOnGantt(PHP_INT_MAX))->isEmpty();
            $this->array(LegacyProjectTask::getDataToDisplayOnGanttForProject(PHP_INT_MAX))->isEmpty();

            // Extensions still own discovery/rendering; their nonexistent rows are
            // checked by the original concrete-record guard before custom rendering.
            ProjectTaskGanttOverride::$roots = [(int)$later->getID(), PHP_INT_MAX];
            ProjectTaskGanttOverride::$calls = [];
            $this->array(ProjectTaskGanttOverride::getDataToDisplayOnGanttForProject($project->getID()))
                ->isIdenticalTo(['custom' => ['id' => (int)$later->getID()]]);
            $this->array(ProjectTaskGanttOverride::$calls)->isIdenticalTo([(int)$later->getID()]);
        } finally {
            ProjectTaskGanttOverride::$roots = ProjectTaskGanttOverride::$calls = [];
            $em->getEventManager()->removeEventListener([Events::postLoad], $listener);
            $em->clear();
            $_SESSION = $session;
        }
    }

    public function testPlanningConflict()
    {
        $this->login();

        $user = getItemByTypeName('User', 'tech');
        $users_id = (int)$user->fields['id'];

        $ptask = new \ProjectTask();
        $this->integer(
            (int)$ptask->add([
              'name'   => 'test'
         ])
        )->isIdenticalTo(0);

        $this->hasSessionMessages(ERROR, ['A linked project is mandatory']);

        $project = new \Project();
        $pid = (int)$project->add([
           'name'   => 'Test project'
        ]);
        $this->integer($pid)->isGreaterThan(0);

        $this->integer(
            (int)$ptask->add([
              'name'                     => 'first test, whole period',
              'projects_id'              => $pid,
              'plan_start_date'          => '2019-08-10',
              'plan_end_date'            => '2019-08-20',
              'projecttasktemplates_id'  => 0
         ])
        )->isGreaterThan(0);
        $this->hasNoSessionMessages([ERROR, WARNING]);
        $task_id = $ptask->fields['id'];

        $team = new \ProjectTaskTeam();
        $tid = (int)$team->add([
           'projecttasks_id' => $ptask->fields['id'],
           'itemtype'        => \User::getType(),
           'items_id'        => $users_id
        ]);
        $this->hasNoSessionMessages([ERROR, WARNING]);
        $this->integer($tid)->isGreaterThan(0);

        $this->integer(
            (int)$ptask->add([
              'name'                     => 'test, subperiod',
              'projects_id'              => $pid,
              'plan_start_date'          => '2019-08-13',
              'plan_end_date'            => '2019-08-14',
              'projecttasktemplates_id'  => 0
         ])
        )->isGreaterThan(0);
        $this->hasNoSessionMessages([ERROR, WARNING]);

        $team = new \ProjectTaskTeam();
        $tid = (int)$team->add([
           'projecttasks_id' => $ptask->fields['id'],
           'itemtype'        => \User::getType(),
           'items_id'        => $users_id
        ]);

        $usr_str = '<a href="' . $user->getFormURLWithID($users_id) . '">' . $user->getName() . '</a>';
        $this->hasSessionMessages(
            WARNING,
            [
              "The user $usr_str is busy at the selected timeframe.<br/>- Project task: from 2019-08-13 00:00 to 2019-08-14 00:00:<br/><a href='".
              $ptask->getFormURLWithID($task_id)."'>first test, whole period</a><br/>"
         ]
        );
        $this->integer($tid)->isGreaterThan(0);

        //check when updating. first create a new task out of existing bouds
        $this->integer(
            (int)$ptask->add([
              'name'                     => 'test subperiod, out of bounds',
              'projects_id'              => $pid,
              'plan_start_date'          => '2018-08-13',
              'plan_end_date'            => '2018-08-24',
              'projecttasktemplates_id'  => 0
         ])
        )->isGreaterThan(0);
        $this->hasNoSessionMessages([ERROR, WARNING]);

        $team = new \ProjectTaskTeam();
        $tid = (int)$team->add([
           'projecttasks_id' => $ptask->fields['id'],
           'itemtype'        => \User::getType(),
           'items_id'        => $users_id
        ]);
        $this->hasNoSessionMessages([ERROR, WARNING]);
        $this->integer($tid)->isGreaterThan(0);

        $this->boolean(
            $ptask->update([
              'id'                       => $ptask->fields['id'],
              'name'                     => 'test subperiod, no longer out of bounds',
              'projects_id'              => $pid,
              'plan_start_date'          => '2019-08-13',
              'plan_end_date'            => '2019-08-24',
              'projecttasktemplates_id'  => 0
         ])
        )->isTrue();
        $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'])
           ->isNotEmpty()
           ->hasKey(WARNING);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = []; //reset

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

        $ttask = new \TicketTask();
        $ttask_id = (int)$ttask->add([
           'name'               => 'A ticket task in bounds',
           'content'            => 'A ticket task in bounds',
           'tickets_id'         => $tid,
           'plan'               => [
              'begin'  => '2019-08-11',
              'end'    => '2019-08-12'
           ],
           'users_id_tech'      => $users_id,
           'tasktemplates_id'   => 0
        ]);
        $usr_str = '<a href="' . $user->getFormURLWithID($users_id) . '">' . $user->getName() . '</a>';

        $this->hasSessionMessages(
            WARNING,
            [
              "The user $usr_str is busy at the selected timeframe.<br/>- Project task: from 2019-08-11 00:00 to 2019-08-12 00:00:<br/><a href='".
              $ptask->getFormURLWithID($task_id)."'>first test, whole period</a><br/>"
         ]
        );
        $this->integer($ttask_id)->isGreaterThan(0);
    }
}


class ProjectTaskGanttOverride extends LegacyProjectTask
{
    public static array $roots = [];
    public static array $calls = [];

    public function find($condition = [], $order = [], $limit = null)
    {
        return array_map(static fn (int $id): array => ['id' => $id], self::$roots);
    }

    public static function getDataToDisplayOnGantt($ID)
    {
        self::$calls[] = (int)$ID;
        return ['custom' => ['id' => (int)$ID]];
    }
}
