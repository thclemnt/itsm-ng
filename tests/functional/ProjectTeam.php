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
use itsmng\Database\Entity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ProjectRepository;

class ProjectTeam extends DbTestCase
{
    public function notificationTeamProvider(): array
    {
        return [[false], [true]];
    }

    /** @dataProvider notificationTeamProvider */
    public function testNotificationRecipientsUseOwnedScalarMembers(bool $taskTeam): void
    {
        global $DB, $CFG_GLPI;

        $this->login();
        $rootId = getItemByTypeName('Entity', '_test_root_entity', true);
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $em = new class($connection, Orm::configuration($connection->getDatabasePlatform())) extends \Doctrine\ORM\EntityManager {
            public int $queries = 0;

            public function createQuery(string $dql = ''): \Doctrine\ORM\Query
            {
                ++$this->queries;
                return parent::createQuery($dql);
            }
        };
        $loads = new class {
            public int $count = 0;

            public function postLoad(): void
            {
                ++$this->count;
            }
        };
        $em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $loads);
        try {
            $root = $em->getReference(Entity\Entity::class, $rootId);
            $parentClass = $taskTeam ? Entity\ProjectTask::class : Entity\Project::class;
            $linkClass = $taskTeam ? Entity\ProjectTaskTeam::class : Entity\ProjectTeam::class;
            $parentProperty = $taskTeam ? 'projecttasks' : 'projects';
            $parent = new $parentClass();
            $parent->name = 'Recipient projection ' . $this->getUniqueString();
            $parent->entities = $root;
            $other = new $parentClass();
            $other->name = 'Other recipient projection ' . $this->getUniqueString();
            $other->entities = $root;
            $em->persist($parent);
            $em->persist($other);
            $link = static function ($member, string $kind, $owner) use ($em, $linkClass, $parentProperty): void {
                $row = new $linkClass();
                $row->$parentProperty = $owner;
                $row->itemtype = $kind;
                $property = $linkClass::memberAssociation($kind);
                $row->$property = $member;
                $em->persist($row);
            };
            $users = [];
            for ($i = 0; $i < 3; ++$i) {
                $user = new Entity\User();
                $user->entities = $root;
                $user->name = 'recipient-' . $this->getUniqueString();
                $user->language = 'fr_FR';
                $user->is_active = $i !== 1;
                $user->authtype = \Auth::DB_GLPI;
                $em->persist($user);
                $email = new Entity\UserEmail();
                $email->users = $user;
                $email->email = 'user' . $i . '@example.test';
                $email->is_default = true;
                $em->persist($email);
                if ($i < 2) {
                    $grant = new Entity\ProfileUser();
                    $grant->users = $user;
                    $grant->profiles = $em->getReference(Entity\Profile::class, 4);
                    $grant->entities = $root;
                    $em->persist($grant);
                }
                $users[] = $user;
                $link($user, 'User', $parent);
            }
            $contact = new Entity\Contact();
            $contact->entities = $root;
            $contact->name = "O'Quoted";
            $contact->firstname = 'First';
            $contact->email = 'CONTACT0@example.test';
            $em->persist($contact);
            $link($contact, 'Contact', $parent);
            $em->flush();
            $repository = new ProjectRepository($em);
            $select = static fn (int $id, string $kind): array => $taskTeam
                ? $repository->taskTeamRecipients($id, $kind) : $repository->projectTeamRecipients($id, $kind);
            $em->queries = 0;
            $this->array($select($parent->id, 'Contact'))->hasSize(1);
            $this->integer($em->queries)->isIdenticalTo(1);
            $contacts = [$contact];
            for ($i = 1; $i < 25; ++$i) {
                $entry = new Entity\Contact();
                $entry->entities = $root;
                $entry->name = $i === 1 ? null : 'Contact ' . $i;
                $entry->firstname = null;
                $entry->email = $i === 2 ? null : 'contact' . $i . '@example.test';
                $em->persist($entry);
                $link($entry, 'Contact', $parent);
                $contacts[] = $entry;
            }
            $supplier = new Entity\Supplier();
            $supplier->entities = $root;
            $supplier->name = '';
            $supplier->email = 'supplier@example.test';
            $em->persist($supplier);
            $link($supplier, 'Supplier', $parent);
            // Same member on another parent must not add a duplicate selection.
            $link($contact, 'Contact', $other);
            $em->flush();
            $parentId = $parent->id;
            $contactId = $contact->id;
            $userId = $users[0]->id;
            $expectedContactIds = array_map(static fn ($member): int => $member->id, $contacts);
            $em->clear();
            $loads->count = 0;
            $em->queries = 0;
            $rows = $select($parentId, 'Contact');
            $this->array(array_column($rows, 'id'))->isIdenticalTo($expectedContactIds);
            $this->integer($em->queries)->isIdenticalTo(1);
            $this->variable($rows[1]['name'])->isNull();
            $this->variable($rows[2]['email'])->isNull();
            $this->array($select($parentId, 'User'))->hasSize(3);
            $this->array($select($parentId, 'Supplier'))->isIdenticalTo([
                ['id' => $supplier->id, 'name' => '', 'email' => 'supplier@example.test'],
            ]);
            $this->array($select(-1, 'Contact'))->isEmpty();
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();

            $model = $taskTeam ? new \ProjectTask() : new \Project();
            $this->boolean($model->getFromDB($parentId))->isTrue();
            $target = $taskTeam ? new \NotificationTargetProjectTask($rootId, 'new', $model)
                : new \NotificationTargetProject($rootId, 'new', $model);
            $target->setMode(\Notification_NotificationTemplate::MODE_MAIL)->setEvent(\NotificationEventMailing::class);
            $target->addTeamUsers();
            // Account state and actual profile scope are still admitted by the public target.
            $this->array(array_keys($target->target))->isIdenticalTo(['user0@example.test']);
            $this->integer($target->target['user0@example.test']['users_id'])->isIdenticalTo($userId);
            $this->string($target->target['user0@example.test']['language'])->isIdenticalTo('fr_FR');
            $target->addTeamContacts();
            $target->addTeamSuppliers();
            $this->array($target->target)->hasSize(26)->hasKey('contact0@example.test')->hasKey('supplier@example.test');
            $legacyContact = new \Contact();
            $this->boolean($legacyContact->getFromDB($contactId))->isTrue();
            $this->string($target->target['contact0@example.test']['username'])->isIdenticalTo($legacyContact->getName());
            $this->string($target->target['contact1@example.test']['username'])->isIdenticalTo(NOT_AVAILABLE);
            $this->string($target->target['supplier@example.test']['username'])->isIdenticalTo(NOT_AVAILABLE);
            $this->string($target->target['contact0@example.test']['language'])->isIdenticalTo($CFG_GLPI['language']);
            $target->addTeamContacts();
            $this->array($target->target)->hasSize(26);

            $managed = $em->find(Entity\Contact::class, $contactId);
            $this->integer($loads->count)->isGreaterThan(0);
            $before = $loads->count;
            $connection->update('glpi_contacts', ['firstname' => 'Fresh', 'email' => 'fresh@example.test'], ['id' => $contactId]);
            $fresh = $select($parentId, 'Contact');
            $this->string($fresh[0]['firstname'])->isIdenticalTo('Fresh');
            $this->string($managed->firstname)->isIdenticalTo('First');
            $this->integer($loads->count)->isIdenticalTo($before);
            $target->target = [];
            $target->addTeamContacts();
            $this->array($target->target)->hasSize(24)->hasKey('fresh@example.test')->notHasKey('contact0@example.test');
            $this->string($target->target['fresh@example.test']['username'])->contains('Fresh');
            $connection->update('glpi_users', ['is_active' => false], ['id' => $userId], ['is_active' => \Doctrine\DBAL\Types\Types::BOOLEAN]);
            $target->target = [];
            $target->addTeamUsers();
            $this->array($target->target)->isEmpty();
            $this->object($em->getConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $em->clear();
        }
    }

    public function testGetTeamForReturnsAvailableTypes()
    {
        $this->login();

        $project = new \Project();
        $project_id = $project->add([
           'name' => 'project-team-' . $this->getUniqueString(),
        ]);
        $this->integer((int)$project_id)->isGreaterThan(0);

        $user_id = getItemByTypeName('User', 'tech', true);
        $this->integer((int)$user_id)->isGreaterThan(0);

        $relation = new \ProjectTeam();
        $relation_id = $relation->add([
           'projects_id' => $project_id,
           'itemtype'    => 'User',
           'items_id'    => $user_id,
        ]);
        $this->integer((int)$relation_id)->isGreaterThan(0);

        $team = \ProjectTeam::getTeamFor($project_id);
        $this->array($team)->hasKey('User')->hasKey('Group')->hasKey('Supplier')->hasKey('Contact');
        $this->integer((int)count($team['User']))->isEqualTo(1);
        $this->string($team['User'][0]['itemtype'])->isEqualTo('User');
        $this->integer((int)$team['User'][0]['items_id'])->isEqualTo($user_id);
    }
}
