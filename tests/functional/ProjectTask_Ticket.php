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
use Project as LegacyProject;
use ProjectTask as LegacyProjectTask;
use ProjectTask_Ticket as LegacyProjectTaskTicket;
use ReflectionProperty;
use itsmng\Database\Entity\ProjectTask as ProjectTaskEntity;
use itsmng\Database\Entity\Ticket as TicketEntity;
use itsmng\Database\Orm;

class ProjectTask_Ticket extends DbTestCase
{
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
