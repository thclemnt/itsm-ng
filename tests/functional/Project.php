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
use Computer;
use Document;
use Document_Item;
use Item_Project;
use NotificationTargetProject;
use DbTestCase;
use Doctrine\ORM\Events;
use Project as LegacyProject;
use ProjectState;
use ProjectTask;
use ProjectTeam;
use Session;
use User;
use itsmng\Database\Entity\Document as DocumentEntity;
use itsmng\Database\Entity\ItemProject as ItemProjectEntity;
use itsmng\Database\Entity\Project as ProjectEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ProjectRepository;

/* Test for inc/project.class.php */
class Project extends DbTestCase
{
    public function testNotificationDocumentAndAssetSnapshotsRetainLiveOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)Session::getActiveEntity();
            $project = $this->createItem(LegacyProject::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
            $computer = $this->createItem(Computer::class, [
                'name' => 'Before document formatting', 'serial' => 'Notification serial', 'entities_id' => $entity,
            ]);
            $binding = $this->createItem(Item_Project::class, [
                'projects_id' => $project->getID(), 'itemtype' => Computer::class, 'items_id' => $computer->getID(),
            ]);
            $documents = [];
            foreach (['Second alphabetically', 'First alphabetically'] as $name) {
                $document = $this->createItem(Document::class, [
                    'name' => $name, 'link' => 'https://example.invalid/project-document', 'entities_id' => $entity,
                ]);
                $this->createItem(Document_Item::class, [
                    'documents_id' => $document->getID(), 'itemtype' => LegacyProject::class, 'items_id' => $project->getID(),
                ]);
                $documents[] = $document;
            }
            $connection = $DB->getDoctrineConnection();
            $owner = Orm::create($DB);
            $dirty = $owner->find(DocumentEntity::class, (int)$documents[0]->getID());
            $dirty->name = 'Unflushed document name';
            $managedBinding = $owner->find(ItemProjectEntity::class, (int)$binding->getID());
            $target = new class ($entity, 'new', $project) extends NotificationTargetProject {
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
            $target->onDocument = function () use ($connection, $computer, $documents): void {
                $this->boolean($connection->isApplicationEntityManagerActive())->isFalse();
                $this->integer($connection->update('glpi_documents', ['name' => 'Written after document snapshot'], ['id' => $documents[0]->getID()]))->isIdenticalTo(1);
                $this->integer($connection->update('glpi_computers', ['name' => 'Written before asset snapshot'], ['id' => $computer->getID()]))->isIdenticalTo(1);
            };
            $options = ['additionnaloption' => ['usertype' => NotificationTargetProject::GLPI_USER]];
            $depth = $connection->getTransactionNestingLevel();
            $data = $target->getForTemplate('new', $options);
            $this->array(array_column($data['documents'], '##document.id##'))
                ->isIdenticalTo(array_map(static fn ($document): int => (int)$document->getID(), $documents));
            $this->array(array_column($data['documents'], '##document.name##'))
                ->isIdenticalTo(['Second alphabetically', 'First alphabetically']);
            $this->string($data['documents'][0]['##document.weblink##'])->isIdenticalTo('https://example.invalid/project-document');
            $this->string($data['documents'][0]['##document.url##'])->contains('redirect=document_' . $documents[0]->getID());
            $this->string($data['documents'][0]['##document.downloadurl##'])->contains('docid=' . $documents[0]->getID());
            $this->integer($data['##project.numberofdocuments##'])->isIdenticalTo(2);
            $this->array($data['items'])->hasSize(1);
            $this->string($data['items'][0]['##item.name##'])->isIdenticalTo('Written before asset snapshot');
            $this->string($data['items'][0]['##item.serial##'])->isIdenticalTo('Notification serial');
            $this->integer($data['##project.numberofitems##'])->isIdenticalTo(1);
            Orm::read($DB, function ($nested) use ($documents, $target, $options): void {
                $retained = $nested->find(DocumentEntity::class, (int)$documents[0]->getID());
                $retained->name = 'Nested unflushed document';
                $current = $target->getForTemplate('new', $options);
                $this->string($current['documents'][0]['##document.name##'])->isIdenticalTo('Written after document snapshot');
                $this->boolean($nested->contains($retained))->isTrue();
                $this->string($retained->name)->isIdenticalTo('Nested unflushed document');
            });
            $this->boolean($owner->contains($dirty))->isTrue();
            $this->string($dirty->name)->isIdenticalTo('Unflushed document name');
            $this->boolean($owner->contains($managedBinding))->isTrue();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
        } finally {
            $_SESSION = $session;
        }
    }

    public function testChildIdentifiersPreserveRichRenderingAndCustomSelection(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $session = $_SESSION;
        $entity = (int)Session::getActiveEntity();
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
            $prefix = 'Project children ' . $this->getUniqueString();
            $owner = $this->createItem(User::class, [
                'name' => $prefix . ' owner', 'entities_id' => $entity, 'authtype' => Auth::DB_GLPI,
            ]);
            $this->integer((int)$owner->getID())->isNotIdenticalTo((int)Session::getLoginUserID());
            $parent = $this->createItem(LegacyProject::class, [
                'name' => $prefix . ' parent', 'entities_id' => $entity, 'users_id' => Session::getLoginUserID(),
            ]);
            $other = $this->createItem(LegacyProject::class, ['name' => $prefix . ' other', 'entities_id' => $entity]);
            $state = $this->createItem(ProjectState::class, ['name' => $prefix . ' state', 'color' => '#123456']);
            $children = [];
            foreach (['team', 'denied', 'deleted', 'other'] as $kind) {
                $children[$kind] = $this->createItem(LegacyProject::class, [
                    'name' => $prefix . ' ' . $kind, 'content' => $prefix . ' detail ' . $kind,
                    'projects_id' => $kind === 'other' ? $other->getID() : $parent->getID(),
                    'entities_id' => $entity, 'users_id' => $owner->getID(), 'projectstates_id' => $state->getID(),
                ]);
            }
            $this->createItem(ProjectTeam::class, [
                'projects_id' => $children['team']->getID(), 'itemtype' => 'User', 'items_id' => Session::getLoginUserID(),
            ]);
            $this->boolean($children['deleted']->delete(['id' => $children['deleted']->getID()]))->isTrue();
            $repository = new ProjectRepository($em);
            $expected = [(int)$children['team']->getID(), (int)$children['denied']->getID()];
            $ids = $repository->childIds((int)$parent->getID());
            $actual = $ids;
            sort($expected);
            sort($actual);
            $this->array($actual)->isIdenticalTo($expected);
            // No ORDER BY existed on the original find; compare membership,
            // then verify the renderer consumes the selector's returned order.
            $old = array_map('intval', array_column($parent->find(['projects_id' => $parent->getID(), 'is_deleted' => 0]), 'id'));
            sort($old);
            $this->array($actual)->isIdenticalTo($old);
            $this->array($repository->childIds(0))->contains((int)$parent->getID());
            $this->array($repository->childIds(PHP_INT_MAX))->isEmpty();
            $this->integer($listener->loaded)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();

            $managed = $em->find(ProjectEntity::class, (int)$children['team']->getID());
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $this->boolean($children['team']->update([
                'id' => $children['team']->getID(), 'projects_id' => $other->getID(),
            ]))->isTrue();
            $this->array($repository->childIds((int)$parent->getID()))->isIdenticalTo([(int)$children['denied']->getID()]);
            $this->integer((int)$em->getClassMetadata(ProjectEntity::class)
                ->getIdentifierValues($managed->projects)['id'])->isIdenticalTo((int)$parent->getID());
            $this->boolean($em->contains($managed))->isTrue();
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $this->boolean($children['team']->update([
                'id' => $children['team']->getID(), 'projects_id' => $parent->getID(),
            ]))->isTrue();

            $_SESSION['glpiactiveprofile']['project'] = LegacyProject::READMY;
            $this->boolean($parent->can($parent->getID(), READ))->isTrue();
            $this->boolean($children['team']->getFromDB($children['team']->getID()))->isTrue();
            $this->boolean($children['team']->canViewItem())->isTrue();
            $this->boolean($children['denied']->canViewItem())->isFalse();
            $ids = $repository->childIds((int)$parent->getID());
            ob_start();
            try {
                $parent->showChildren();
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->string($html)->contains($children['team']->getLinkURL() . '&amp;forcetab=Project$')
                ->contains($prefix . ' detail team')->contains($prefix . ' state')->contains("bgcolor='#123456'")
                ->contains($prefix . ' denied')->notContains($children['denied']->getLinkURL())
                ->notContains($prefix . ' deleted')->notContains($prefix . ' other');
            $this->boolean(strpos($html, '<span class=\'b\'>' . $prefix . ' ' . ($ids[0] === (int)$children['team']->getID() ? 'team' : 'denied'))
                < strpos($html, '<span class=\'b\'>' . $prefix . ' ' . ($ids[1] === (int)$children['team']->getID() ? 'team' : 'denied')))->isTrue();

            $custom = new class () extends LegacyProject {
                public array $selection = [];
                public array $calls = [];
                public static function getTable($classname = null)
                {
                    return LegacyProject::getTable();
                }
                public function find($condition = [], $order = [], $limit = null)
                {
                    $this->calls[] = [$condition, $order, $limit];
                    return $this->selection;
                }
            };
            $custom->fields = $parent->fields;
            $custom->selection = [['id' => $children['other']->getID()], ['id' => $children['denied']->getID()]];
            ob_start();
            try {
                $custom->showChildren();
                $customHtml = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->array($custom->calls)->isIdenticalTo([[['projects_id' => $parent->getID(), 'is_deleted' => 0], [], null]]);
            $this->string($customHtml)->contains($prefix . ' other')->contains($prefix . ' denied')->notContains($prefix . ' team');
            $this->boolean(strpos($customHtml, $prefix . ' other') < strpos($customHtml, $prefix . ' denied'))->isTrue();
        } finally {
            $em->getEventManager()->removeEventListener([Events::postLoad], $listener);
            $em->clear();
            $_SESSION = $session;
        }
    }

    public function testAutocalculatePercentDone()
    {

        $this->login(); // must be logged as ProjectTask uses Session::getLoginUserID()

        $project = new \Project();
        $project_id_1 = $project->add([
           'name' => 'Project 1',
           'auto_percent_done' => 1
        ]);
        $this->integer((int) $project_id_1)->isGreaterThan(0);
        $project_id_2 = $project->add([
           'name' => 'Project 2',
           'auto_percent_done' => 1,
           'projects_id' => $project_id_1
        ]);
        $this->integer((int) $project_id_2)->isGreaterThan(0);
        $project_id_3 = $project->add([
           'name' => 'Project 3',
           'projects_id' => $project_id_2
        ]);
        $this->integer((int) $project_id_3)->isGreaterThan(0);

        $projecttask = new \ProjectTask();
        $projecttask_id_1 = $projecttask->add([
           'name' => 'Project Task 1',
           'auto_percent_done' => 1,
           'projects_id' => $project_id_2,
           'projecttasktemplates_id' => 0
        ]);
        $this->integer((int) $projecttask_id_1)->isGreaterThan(0);
        $projecttask_id_2 = $projecttask->add([
           'name' => 'Project Task 2',
           'projects_id' => 0,
           'projecttasks_id' => $projecttask_id_1,
           'projecttasktemplates_id' => 0
        ]);
        $this->integer((int) $projecttask_id_2)->isGreaterThan(0);

        $project_1 = new \Project();
        $this->boolean($project_1->getFromDB($project_id_1))->isTrue();
        $project_2 = new \Project();
        $this->boolean($project_2->getFromDB($project_id_2))->isTrue();
        $project_3 = new \Project();
        $this->boolean($project_3->getFromDB($project_id_3))->isTrue();
        $this->boolean($project_3->update([
           'id'           => $project_id_3,
           'percent_done' => '10'
        ]))->isTrue();

        // Reload projects to get newest values
        $this->boolean($project_1->getFromDB($project_id_1))->isTrue();
        $this->boolean($project_2->getFromDB($project_id_2))->isTrue();
        // Test parent and parent's parent percent done
        $this->integer($project_2->fields['percent_done'])->isEqualTo(5);
        $this->integer($project_1->fields['percent_done'])->isEqualTo(5);

        $projecttask_1 = new \ProjectTask();
        $this->boolean($projecttask_1->getFromDB($projecttask_id_1))->isTrue();
        $projecttask_2 = new \ProjectTask();
        $this->boolean($projecttask_2->getFromDB($projecttask_id_2))->isTrue();

        $this->boolean($projecttask_2->update([
           'id'           => $projecttask_id_2,
           'percent_done' => '40'
        ]))->isTrue();

        // Reload projects and tasks to get newest values
        $this->boolean($project_1->getFromDB($project_id_1))->isTrue();
        $this->boolean($project_2->getFromDB($project_id_2))->isTrue();
        $this->boolean($project_3->getFromDB($project_id_3))->isTrue();
        $this->boolean($projecttask_1->getFromDB($projecttask_id_1))->isTrue();
        $this->integer($projecttask_1->fields['percent_done'])->isEqualTo(40);
        // Check that the child project wasn't changed
        $this->integer($project_3->fields['percent_done'])->isEqualTo(10);
        $this->integer($project_2->fields['percent_done'])->isEqualTo(25);
        $this->integer($project_1->fields['percent_done'])->isEqualTo(25);

        // Test that percent done updates on delete and restore
        $project_3->delete(['id' => $project_id_3]);
        $this->boolean($project_2->getFromDB($project_id_2))->isTrue();
        $this->integer($project_2->fields['percent_done'])->isEqualTo(40);
        $project_3->restore(['id' => $project_id_3]);
        $this->boolean($project_2->getFromDB($project_id_2))->isTrue();
        $this->integer($project_2->fields['percent_done'])->isEqualTo(25);
    }

    public function testCreateFromTemplate()
    {
        $this->login();

        $date = date('Y-m-d H:i:s');
        $_SESSION['glpi_currenttime'] = $date;

        $project = new \Project();

        // Create a project template
        $template_id = $project->add(
            [
              'name'         => $this->getUniqueString(),
              'entities_id'  => 0,
              'is_recursive' => 1,
              'is_template'  => 1,
         ]
        );
        $this->integer($template_id)->isGreaterThan(0);

        $project_task = new ProjectTask();
        $task1_id = $project_task->add(
            [
              'name'         => $this->getUniqueString(),
              'projects_id'  => $template_id,
              'entities_id'  => 0,
              'is_recursive' => 1,
         ]
        );
        $this->integer($task1_id)->isGreaterThan(0);
        $task2_id = $project_task->add(
            [
              'name'         => $this->getUniqueString(),
              'projects_id'  => $template_id,
              'entities_id'  => 0,
              'is_recursive' => 1,
         ]
        );
        $this->integer($task2_id)->isGreaterThan(0);

        // Create from template
        $entity_id = getItemByTypeName('Entity', '_test_child_2', true);
        $project_id = $project->add(
            [
              'id'           => $template_id,
              'name'         => $this->getUniqueString(),
              'entities_id'  => $entity_id,
              'is_recursive' => 0,
         ]
        );
        $this->integer($project_id)->isGreaterThan(0);
        $this->integer($project_id)->isNotEqualTo($template_id);

        // Check created project
        $this->integer($project->fields['entities_id'])->isEqualTo($entity_id);
        $this->integer($project->fields['is_recursive'])->isEqualTo(0);

        // Check created tasks
        $tasks_data = getAllDataFromTable($project_task->getTable(), ['projects_id' => $project_id]);
        $this->array($tasks_data)->hasSize(2);
        foreach ($tasks_data as $task_data) {
            $this->integer($task_data['entities_id'])->isEqualTo($entity_id);
            $this->integer($task_data['is_recursive'])->isEqualTo(0);
        }
    }

    public function testClone()
    {
        // Create a basic project
        $project_name = 'Project testClone' . mt_rand();
        $project_input = [
           'name'     => $project_name,
           'priority' => 5,
        ];
        $this->createItems('Project', [$project_input]);
        $projects_id = getItemByTypeName("Project", $project_name, true);

        // Create a user
        $user_name = 'Project testClone - User' . mt_rand();
        $this->createItems('User', [['name' => $user_name]]);
        $users_id = getItemByTypeName("User", $user_name, true);

        // Create a group
        $group_name = 'Project testClone - Group' . mt_rand();
        $this->createItems('Group', [['name' => $group_name]]);
        $groups_id = getItemByTypeName("Group", $group_name, true);

        // Add team to project
        $this->createItems('ProjectTeam', [
           [
              'projects_id' => $projects_id,
              'itemtype'    => 'User',
              'items_id'    => $users_id,
           ],
           [
              'projects_id' => $projects_id,
              'itemtype'    => 'Group',
              'items_id'    => $groups_id,
           ],
        ]);

        // Load current project
        $project = new \Project();
        $this->boolean($project->getFromDB($projects_id))->isTrue();

        // Clone project
        $projects_id_clone = $project->clone();
        $this->integer($projects_id_clone)->isGreaterThan(0);

        // Load clone
        $project_clone = new \Project();
        $this->boolean($project_clone->getFromDB($projects_id_clone))->isTrue();

        // Check name
        $this->string($project_clone->fields['name'])->isEqualTo("$project_input[name] (copy)");
        unset($project_clone->fields['name'], $project_input['name']);

        // Check basics fields
        foreach (array_keys($project_input) as $field) {
            $this->variable($project_clone->fields[$field])->isEqualTo($project->fields[$field]);
        }

        // Load project team
        $project_team = new ProjectTeam();
        $team = [];
        foreach ($project_team->find(['projects_id' => $projects_id]) as $row) {
            $team[] = [
               'itemtype' => $row['itemtype'],
               'items_id' => $row['items_id'],
            ];
        }

        // Load clone team
        $team_clone = [];
        foreach ($project_team->find(['projects_id' => $projects_id_clone]) as $row) {
            $team_clone[] = [
               'itemtype' => $row['itemtype'],
               'items_id' => $row['items_id'],
            ];
        }

        // Compare teams
        $this->array($team_clone)->isEqualTo($team);
    }
}
