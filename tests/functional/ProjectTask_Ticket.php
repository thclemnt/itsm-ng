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
use Doctrine\ORM\EntityManager;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Event\PostLoadEventArgs;
use ProjectState as LegacyProjectState;
use Ticket as LegacyTicket;
use itsmng\Database\Entity\ProjectState as ProjectStateEntity;
use itsmng\Database\Repository\ProjectRepository;
use mock\DBmysql as ProjectStateAdapterProbe;
use tests\fixtures\ScalarReadProbe;
use Project as LegacyProject;
use ProjectTask as LegacyProjectTask;
use ProjectTask_Ticket as LegacyProjectTaskTicket;
use ReflectionProperty;
use itsmng\Database\Entity\ProjectTask as ProjectTaskEntity;
use itsmng\Database\Entity\Ticket as TicketEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ProjectTaskRepository;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

class ProjectTask_Ticket extends DbTestCase
{
    public function testTicketProjectChooserExcludesCurrentFinishedStatesWithoutTouchingLiveOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        $original = $DB;
        $writer = null;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)$_SESSION['glpiactive_entity'];
            $token = $this->getUniqueString();
            $finished = $this->createItem(LegacyProjectState::class, ['name' => 'Finished ' . $token, 'is_finished' => true]);
            $open = $this->createItem(LegacyProjectState::class, ['name' => 'Open ' . $token, 'is_finished' => false]);
            $finishedProject = $this->createItem(LegacyProject::class, ['name' => 'Excluded project ' . $token, 'entities_id' => $entity, 'projectstates_id' => $finished->getID()]);
            $openProject = $this->createItem(LegacyProject::class, ['name' => 'Selectable project ' . $token, 'entities_id' => $entity, 'projectstates_id' => $open->getID()]);
            $unconfiguredProject = $this->createItem(LegacyProject::class, ['name' => 'No-state project ' . $token, 'entities_id' => $entity]);
            $ticket = $this->createItem(LegacyTicket::class, ['name' => 'Chooser ticket ' . $token, 'content' => 'Project chooser', 'entities_id' => $entity]);
            $connection = $DB->getDoctrineConnection();
            $writer = Orm::create($DB);
            $live = $writer->find(ProjectStateEntity::class, (int)$finished->getID());
            $live->is_finished = false;
            $stateTable = LegacyProjectState::getTable();
            $read = static fn (): array => Orm::read($GLOBALS['DB'], static fn (EntityManager $manager): array =>
                (new ProjectRepository($manager))->finishedStateIds($stateTable));
            $ids = $read();
            $this->array(array_map('intval', $ids))->contains((int)$finished->getID())->notContains((int)$open->getID());
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                foreach ($ids as $id) { $this->integer($id); }
            }
            $render = static fn () => LegacyProjectTaskTicket::showForTicket($ticket);
            $this->output($render)->contains($openProject->getField('name'))->contains($unconfiguredProject->getField('name'))->notContains($finishedProject->getField('name'));
            Orm::read($DB, function (EntityManager $outer) use ($finished, $render, $openProject, $finishedProject, $unconfiguredProject): void {
                $owned = $outer->find(ProjectStateEntity::class, (int)$finished->getID());
                $owned->is_finished = false;
                $this->output($render)->contains($openProject->getField('name'))->contains($unconfiguredProject->getField('name'))->notContains($finishedProject->getField('name'));
                $this->boolean($outer->contains($owned))->isTrue();
                $this->boolean($owned->is_finished)->isFalse();
            });
            $this->boolean($writer->contains($live))->isTrue();
            $this->boolean($live->is_finished)->isFalse();
            $this->integer($connection->update('glpi_projectstates', ['is_finished' => false], ['id' => $finished->getID()]))->isIdenticalTo(1);
            $this->array(array_map('intval', $read()))->notContains((int)$finished->getID());
            $this->array(array_map('intval', $ids))->contains((int)$finished->getID());
            $this->output($render)->contains($finishedProject->getField('name'))->contains($openProject->getField('name'));
            $this->integer($connection->update('glpi_projectstates', ['is_finished' => true], ['id' => $finished->getID()]))->isIdenticalTo(1);
            // Exercise the real empty-state catalogue without leaving shared fixtures altered.
            $stateFlags = $connection->fetchAllAssociative('SELECT id, is_finished FROM glpi_projectstates ORDER BY id');
            $stateDepth = $connection->getTransactionNestingLevel();
            $finishedIds = $read();
            try {
                foreach ($finishedIds as $id) {
                    $connection->update('glpi_projectstates', ['is_finished' => false], ['id' => $id]);
                }
                $this->array($read())->isEmpty();
                $this->output($render)->contains($finishedProject->getField('name'))
                    ->contains($openProject->getField('name'))->contains($unconfiguredProject->getField('name'));
            } finally {
                foreach ($finishedIds as $id) {
                    $connection->update('glpi_projectstates', ['is_finished' => true], ['id' => $id]);
                }
            }
            $this->array($connection->fetchAllAssociative('SELECT id, is_finished FROM glpi_projectstates ORDER BY id'))
                ->isIdenticalTo($stateFlags, 'The empty-catalogue exercise restores every original and fixture state flag');
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($stateDepth);
            $events = new EventManager();
            $listener = new class () {
                public int $loads = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    if ($event->getObject() instanceof ProjectStateEntity) { ++$this->loads; }
                }
            };
            $events->addEventListener(['postLoad'], $listener);
            $probe = new class ($connection) extends ScalarReadProbe {
                public EventManager $events;
                public function getEventManager(): EventManager { return $this->events; }
            };
            $probe->events = $events;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new ProjectStateAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = $probe;
            $this->calling($adapter)->getProvider = $original->getProvider();
            $depth = $connection->getTransactionNestingLevel();
            $DB = $adapter;
            $this->output($render)->contains($openProject->getField('name'))->contains($unconfiguredProject->getField('name'))->notContains($finishedProject->getField('name'));
            $this->integer($listener->loads)->isIdenticalTo(0);
            $finishedQueries = array_values(array_filter($probe->queries, static fn (array $query): bool => isset($query['params']['finished'])));
            $this->array($finishedQueries)->hasSize(1);
            $this->boolean($finishedQueries[0]['params']['finished'])->isTrue();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
            $DB = $original;
            foreach ([LegacyTicket::SOLVED, LegacyTicket::CLOSED] as $status) {
                $ticket->fields['status'] = $status;
                $DB = $adapter;
                $probe->queries = [];
                $this->output($render)->notContains($openProject->getField('name'));
                $this->array(array_values(array_filter($probe->queries, static fn (array $query): bool => isset($query['params']['finished']))))->isEmpty();
                $DB = $original;
            }
        } finally {
            $DB = $original;
            $writer?->clear();
            $_SESSION = $session;
        }
    }


    public function testTicketTabProjectionKeepsNullableFactsAndOwningLinks(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $entity = (int)$_SESSION['glpiactive_entity'];
        $project = $this->createItem('Project', ['entities_id' => $entity, 'name' => 'Tab project ' . $this->getUniqueString(), 'content' => 'Project tooltip']);
        $type = $this->createItem('ProjectTaskType', ['name' => 'Tab type ' . $this->getUniqueString()]);
        $state = $this->createItem('ProjectState', ['name' => 'Tab state ' . $this->getUniqueString()]);
        $father = $this->createItem('ProjectTask', ['entities_id' => $entity, 'name' => 'Father task ' . $this->getUniqueString(), 'projects_id' => $project->getID()]);
        $first = $this->createItem('ProjectTask', ['entities_id' => $entity, 'name' => 'Linked task ' . $this->getUniqueString(), 'projects_id' => $project->getID(),
            'projecttasks_id' => $father->getID(), 'projecttasktypes_id' => $type->getID(), 'projectstates_id' => $state->getID()]);
        $last = $this->createItem('ProjectTask', ['entities_id' => $entity, 'name' => 'Nullable linked task ' . $this->getUniqueString(), 'projects_id' => $project->getID()]);
        $ticket = $this->createItem('Ticket', ['entities_id' => $entity, 'name' => 'Task tab ticket ' . $this->getUniqueString(), 'content' => 'Tab owner']);
        $other = $this->createItem('Ticket', ['entities_id' => $entity, 'name' => 'Other task tab ticket ' . $this->getUniqueString(), 'content' => 'Independent owner']);
        $firstLink = $this->createItem('ProjectTask_Ticket', ['projecttasks_id' => $first->getID(), 'tickets_id' => $ticket->getID()]);
        $lastLink = $this->createItem('ProjectTask_Ticket', ['projecttasks_id' => $last->getID(), 'tickets_id' => $ticket->getID()]);
        $this->createItem('ProjectTask_Ticket', ['projecttasks_id' => $first->getID(), 'tickets_id' => $other->getID()]);
        $connection = $DB->getDoctrineConnection();
        $connection->update('glpi_projecttasks', ['content' => 'Task tooltip', 'percent_done' => 25, 'planned_duration' => 3600,
            'plan_start_date' => '2030-02-03 04:05:06', 'plan_end_date' => '2030-02-03 05:05:06'], ['id' => $first->getID()]);
        $connection->update('glpi_projecttasks', ['projects_id' => null, 'projecttasks_id' => null, 'projecttasktypes_id' => null,
            'projectstates_id' => null, 'plan_start_date' => null, 'plan_end_date' => null], ['id' => $last->getID()]);
        $read = static fn (?int $id): array => Orm::read($DB, static fn (EntityManager $manager): array =>
            (new ProjectTaskRepository($manager))->ticketTabRows($id));
        $rows = $read((int)$ticket->getID());
        $this->integer(count($rows))->isIdenticalTo(2);
        $byId = array_column($rows, null, 'id');
        $facts = $byId[$first->getID()];
        $this->string($facts['tname'])->isIdenticalTo($type->getField('name'));
        $this->string($facts['sname'])->isIdenticalTo($state->getField('name'));
        $this->string($facts['projectname'])->isIdenticalTo($project->getField('name'));
        $this->string($facts['projectcontent'])->isIdenticalTo('Project tooltip');
        $this->string($facts['content'])->isIdenticalTo('Task tooltip');
        $this->integer((int)$facts['projecttasks_id'])->isIdenticalTo((int)$father->getID());
        $this->integer((int)$facts['percent_done'])->isIdenticalTo(25);
        $this->integer((int)$facts['planned_duration'])->isIdenticalTo(3600);
        $this->string($facts['plan_start_date'])->isIdenticalTo('2030-02-03 04:05:06');
        $this->string($facts['plan_end_date'])->isIdenticalTo('2030-02-03 05:05:06');
        foreach (['projects_id', 'projecttasks_id', 'tname', 'sname', 'projectname', 'projectcontent', 'plan_start_date', 'plan_end_date'] as $nullable) {
            $this->variable($byId[$last->getID()][$nullable])->isNull();
        }
        $this->integer(count($read((int)$other->getID())))->isIdenticalTo(1);
        $unlinked = array_map('intval', array_column($read(null), 'id'));
        $this->array($unlinked)->contains((int)$father->getID())->notContains((int)$first->getID(), (int)$last->getID());
        $this->array($read(0))->isEmpty();
        $this->array($read(-1))->isEmpty();
        $rendered = $this->renderLocalTableRows(static fn () => LegacyProjectTaskTicket::showForTicket($ticket));
        $this->integer(count($rendered))->isIdenticalTo(2);
        $taskHref = "href='" . LegacyProjectTask::getFormURLWithID($first->getID()) . "'";
        $taskRows = array_values(array_filter($rendered, static fn (array $row): bool => str_contains($row[1], $taskHref)));
        $this->integer(count($taskRows))->isIdenticalTo(1);
        $this->string($taskRows[0][0])
            ->contains("href='" . LegacyProject::getFormURLWithID($project->getID()) . "'")
            ->contains($project->getField('name'));
        $this->string($taskRows[0][1])->contains($taskHref)->contains($first->getField('name'));
        $this->string($taskRows[0][9])
            ->contains("href='" . LegacyProjectTask::getFormURLWithID($father->getID()) . "'")
            ->contains($father->getField('name'));
        $session = $_SESSION;
        try {
            $_SESSION['glpishowallentities'] = false;
            $_SESSION['glpiactiveentities'] = [];
            $this->integer(count($read((int)$ticket->getID())))->isIdenticalTo(2);
            $this->output(static fn () => LegacyProjectTaskTicket::showForTicket($ticket))->isEmpty();
        } finally {
            $_SESSION = $session;
        }
        $connection->update('glpi_projects', ['name' => 'Fresh project name', 'is_deleted' => true], ['id' => $project->getID()]);
        $connection->update('glpi_projecttasks', ['content' => 'Fresh task tooltip', 'plan_end_date' => null], ['id' => $first->getID()]);
        $fresh = array_column($read((int)$ticket->getID()), null, 'id');
        $this->string($fresh[$first->getID()]['projectname'])->isIdenticalTo('Fresh project name');
        $this->string($fresh[$first->getID()]['content'])->isIdenticalTo('Fresh task tooltip');
        $this->variable($fresh[$first->getID()]['plan_end_date'])->isNull();
        $this->string($facts['plan_end_date'])->isIdenticalTo('2030-02-03 05:05:06');
        $this->boolean($lastLink->delete(['id' => $lastLink->getID()], true))->isTrue();
        $this->integer(count($read((int)$ticket->getID())))->isIdenticalTo(1);
        $this->boolean($ticket->delete(['id' => $ticket->getID()], true))->isTrue();
        $this->array($read((int)$ticket->getID()))->isEmpty();
        $this->boolean($firstLink->getFromDB($firstLink->getID()))->isFalse();
        $this->integer(count($read((int)$other->getID())))->isIdenticalTo(1);
    }

    public function testLinkedTicketActionTimePreservesCurrentValuesAndLifecycle(): void
    {
        global $DB;
        $this->login();
        $project = $this->createItem('Project', ['name' => $this->getUniqueString()]);
        $task = $this->createItem('ProjectTask', ['name' => $this->getUniqueString(), 'projects_id' => $project->getID()]);
        $otherTask = $this->createItem('ProjectTask', ['name' => $this->getUniqueString(), 'projects_id' => $project->getID()]);
        $ticket = $this->createItem('Ticket', ['name' => $this->getUniqueString(), 'content' => 'content', 'actiontime' => 75]);
        $otherTicket = $this->createItem('Ticket', ['name' => $this->getUniqueString(), 'content' => 'content', 'actiontime' => 25]);
        $this->variable(LegacyProjectTaskTicket::getTicketsTotalActionTime($task->getID()))->isNull();
        $link = $this->createItem('ProjectTask_Ticket', ['projecttasks_id' => $task->getID(), 'tickets_id' => $ticket->getID()]);
        $otherLink = $this->createItem('ProjectTask_Ticket', ['projecttasks_id' => $task->getID(), 'tickets_id' => $otherTicket->getID()]);
        $this->createItem('ProjectTask_Ticket', ['projecttasks_id' => $otherTask->getID(), 'tickets_id' => $ticket->getID()]);
        $this->integer(LegacyProjectTaskTicket::getTicketsTotalActionTime($task->getID()))->isIdenticalTo(100);
        $this->integer(LegacyProjectTaskTicket::getTicketsTotalActionTime($otherTask->getID()))->isIdenticalTo(75);
        $caller = Orm::create($DB);
        try {
            $retained = $caller->find(TicketEntity::class, (int)$ticket->getID());
            $retained->actiontime = 999;
            $DB->getDoctrineConnection()->update('glpi_tickets', ['actiontime' => 80], ['id' => $ticket->getID()]);
            $this->integer(LegacyProjectTaskTicket::getTicketsTotalActionTime($task->getID()))->isIdenticalTo(105);
            $this->integer(LegacyProjectTaskTicket::getTicketsTotalActionTime($otherTask->getID()))->isIdenticalTo(80);
            $this->boolean($caller->contains($retained))->isTrue();
            $this->integer($retained->actiontime)->isIdenticalTo(999);
            $this->boolean($otherLink->delete(['id' => $otherLink->getID()], true))->isTrue();
            $this->integer(LegacyProjectTaskTicket::getTicketsTotalActionTime($task->getID()))->isIdenticalTo(80);
            $this->boolean($task->delete(['id' => $task->getID()], true))->isTrue();
            $this->boolean($link->getFromDB($link->getID()))->isFalse();
            $this->variable(LegacyProjectTaskTicket::getTicketsTotalActionTime($task->getID()))->isNull();
            $this->integer(LegacyProjectTaskTicket::getTicketsTotalActionTime($otherTask->getID()))->isIdenticalTo(80);
        } finally {
            $caller->clear();
        }
    }

    public function testGetTicketsTotalActionTime()
    {
        $this->login();

        $project = new \Project();
        $project_id = $project->add([
           'name' => 'projecttask-ticket-project-' . $this->getUniqueString(),
        ]);
        $this->integer((int)$project_id)->isGreaterThan(0);

        $project_task = new \ProjectTask();
        $task_id = $project_task->add([
           'name'                    => 'projecttask-ticket-task-' . $this->getUniqueString(),
           'projects_id'             => $project_id,
           'projecttasktemplates_id' => 0,
        ]);
        $this->integer((int)$task_id)->isGreaterThan(0);

        $ticket_1 = new \Ticket();
        $ticket_1_id = $ticket_1->add([
           'name'       => 'projecttask-ticket-1-' . $this->getUniqueString(),
           'content'    => 'content',
           'actiontime' => 120,
        ]);
        $this->integer((int)$ticket_1_id)->isGreaterThan(0);

        $ticket_2 = new \Ticket();
        $ticket_2_id = $ticket_2->add([
           'name'       => 'projecttask-ticket-2-' . $this->getUniqueString(),
           'content'    => 'content',
           'actiontime' => 60,
        ]);
        $this->integer((int)$ticket_2_id)->isGreaterThan(0);

        $relation = new \ProjectTask_Ticket();
        $this->integer((int)$relation->add([
           'projecttasks_id' => $task_id,
           'tickets_id'      => $ticket_1_id,
        ]))->isGreaterThan(0);
        $this->integer((int)$relation->add([
           'projecttasks_id' => $task_id,
           'tickets_id'      => $ticket_2_id,
        ]))->isGreaterThan(0);

        $this->integer((int)\ProjectTask_Ticket::getTicketsTotalActionTime($task_id))->isEqualTo(180);

        global $DB;
        $connection = $DB->getDoctrineConnection();
        $otherProject = $this->createItem(LegacyProject::class, ['name' => $this->getUniqueString()]);
        $otherTask = $this->createItem(LegacyProjectTask::class, [
            'name' => $this->getUniqueString(), 'projects_id' => $otherProject->getID(),
        ]);
        $connection->update('glpi_projecttasks', ['effective_duration' => 900, 'planned_duration' => 1200], ['id' => $otherTask->getID()]);
        $connection->update('glpi_projecttasks', ['effective_duration' => 30, 'planned_duration' => 600], ['id' => $task_id]);
        $caller = Orm::create($DB);
        try {
            $retained = $caller->find(ProjectTaskEntity::class, (int)$task_id);
            $retained->planned_duration = 777;
            // Two linked tickets must not multiply the task's own duration.
            $this->integer(LegacyProjectTask::getTotalEffectiveDuration($task_id))->isIdenticalTo(210);
            $this->integer(LegacyProjectTask::getTotalEffectiveDurationForProject($project_id))->isIdenticalTo(210);
            $this->integer(LegacyProjectTask::getTotalPlannedDurationForProject($project_id))->isIdenticalTo(600);
            $this->integer(LegacyProjectTask::getTotalEffectiveDurationForProject($otherProject->getID()))->isIdenticalTo(900);
            $this->integer(LegacyProjectTask::getTotalPlannedDurationForProject($otherProject->getID()))->isIdenticalTo(1200);

            $connection->update('glpi_projecttasks', ['effective_duration' => 45, 'planned_duration' => 660], ['id' => $task_id]);
            $connection->update('glpi_tickets', ['actiontime' => 150], ['id' => $ticket_1_id]);
            $this->integer(LegacyProjectTask::getTotalEffectiveDuration($task_id))->isIdenticalTo(255);
            $this->integer(LegacyProjectTask::getTotalEffectiveDurationForProject($project_id))->isIdenticalTo(255);
            $this->integer(LegacyProjectTask::getTotalPlannedDurationForProject($project_id))->isIdenticalTo(660);
            $this->integer(LegacyProjectTask::getTotalEffectiveDuration(-1))->isIdenticalTo(0);
            $this->integer(LegacyProjectTask::getTotalEffectiveDurationForProject(-1))->isIdenticalTo(0);
            $this->integer(LegacyProjectTask::getTotalPlannedDurationForProject(-1))->isIdenticalTo(0);
            $this->boolean($caller->contains($retained))->isTrue();
            $this->integer($retained->planned_duration)->isIdenticalTo(777);
        } finally {
            $caller->clear();
        }

        // Each value boundary is already warm; completed reads must reuse its private manager.
        $managers = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $before = $managers->getValue();
        for ($repeat = 0; $repeat < 16; ++$repeat) {
            LegacyProjectTask::getTotalEffectiveDuration($task_id);
            LegacyProjectTask::getTotalEffectiveDurationForProject($project_id);
            LegacyProjectTask::getTotalPlannedDurationForProject($project_id);
        }
        $this->integer($managers->getValue() - $before)->isIdenticalTo(0);
    }
}
