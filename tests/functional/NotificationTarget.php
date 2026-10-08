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
use DbTestCase;
use Doctrine\ORM\Events;
use Group;
use Notification;
use NotificationEventAjax;
use NotificationTarget as LegacyNotificationTarget;
use NotificationTargetPlanningRecall;
use PlanningExternalEvent;
use Plugin;
use Profile_User;
use ReflectionProperty;
use Reminder;
use User;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\User as UserEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\NotificationRecipientRepository;
use itsmng\Database\Repository\RecordWriter;

/* Test for inc/notificationtarget.class.php */

class NotificationTarget extends DbTestCase
{
    public function testRecipientAdmissionKeepsEligibilityAndCallbackWrites(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $em = null;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', false);
            $_SESSION['glpi_currenttime'] = '2030-06-15 12:00:00';
            $entity = (int)$_SESSION['glpiactive_entity'];
            $user = $this->createItem(User::class, ['name' => 'Admission ' . $this->getUniqueString(),
                'entities_id' => $entity, 'authtype' => Auth::DB_GLPI, 'is_active' => 1,
                'firstname' => 'Before', 'realname' => 'Recipient', 'timezone' => 'Europe/Paris']);
            $id = (int)$user->getID();
            $connection = $DB->getDoctrineConnection();
            $level = $connection->getTransactionNestingLevel();
            $target = new LegacyNotificationTarget($entity);
            $target->setEvent(NotificationEventAjax::class);
            $this->boolean($target->addToRecipientsList(['users_id' => PHP_INT_MAX]))->isFalse();
            // A valid user alone does not grant access to this entity.
            $this->boolean($DB->delete('glpi_profiles_users', ['users_id' => $id]))->isTrue();
            $this->boolean($target->addToRecipientsList(['users_id' => $id]))->isFalse();
            $grant = $this->createItem(Profile_User::class, ['users_id' => $id,
                'profiles_id' => $_SESSION['glpiactiveprofile']['id'], 'entities_id' => 0, 'is_recursive' => 0]);
            $this->boolean($target->addToRecipientsList(['users_id' => $id]))->isFalse();
            $this->boolean($DB->update('glpi_profiles_users', ['entities_id' => $entity], ['id' => $grant->getID()]))->isTrue();

            $valid = ['is_active' => 1, 'is_deleted' => 0, 'begin_date' => null, 'end_date' => null];
            foreach ([['is_active' => 0], ['is_deleted' => 1], ['begin_date' => '2030-06-15 12:00:01'],
                ['end_date' => '2030-06-15 11:59:59']] as $invalid) {
                $this->boolean($DB->update('glpi_users', array_replace($valid, $invalid), ['id' => $id]))->isTrue();
                $this->boolean($target->addToRecipientsList(['users_id' => $id]))->isFalse();
                $this->array($target->target)->isEmpty();
            }
            // Both endpoints are inclusive, with the same legacy datetime representation.
            $this->boolean($DB->update('glpi_users', array_replace($valid, [
                'begin_date' => $_SESSION['glpi_currenttime'], 'end_date' => $_SESSION['glpi_currenttime'],
            ]), ['id' => $id]))->isTrue();
            $em = Orm::create($DB);
            $loads = new class () {
                public int $count = 0;
                public function postLoad(): void
                {
                    ++$this->count;
                }
            };
            $em->getEventManager()->addEventListener([Events::postLoad], $loads);
            $repository = new NotificationRecipientRepository($em);
            $this->boolean($user->getFromDB($id))->isTrue();
            $row = $repository->admissionData($id);
            foreach ($row as $field => $value) {
                $this->variable($value)->isIdenticalTo($user->fields[$field]);
            }
            $this->array($row)->hasSize(10);
            $this->variable($repository->admissionData(PHP_INT_MAX))->isNull();
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->object($em->find(UserEntity::class, $id))->isInstanceOf(UserEntity::class);
            $this->integer($loads->count)->isGreaterThan(0);
            $em->clear();

            $seen = [];
            $plugins->setValue(null, [...$active, 'recipient_admission_fixture']);
            $PLUGIN_HOOKS['add_recipient_to_target'] = ['recipient_admission_fixture' => [
                LegacyNotificationTarget::class => function (LegacyNotificationTarget $delivery) use (&$seen, $DB, $id): void {
                    $seen[] = $delivery->target[$id];
                    $this->array($delivery->recipient_data)->isIdenticalTo(['itemtype' => User::class, 'items_id' => $id]);
                    if (count($seen) === 1) {
                        $this->boolean($DB->update('glpi_users', ['firstname' => 'After', 'timezone' => 'null',
                            'authtype' => Auth::CAS, 'auth_source_code' => null], ['id' => $id]))->isTrue();
                    } elseif (count($seen) === 2) {
                        $this->boolean($DB->update('glpi_users', ['is_active' => 0], ['id' => $id]))->isTrue();
                    }
                },
            ]];
            $target->addToRecipientsList(['users_id' => $id, 'language' => 'fr_FR']);
            $target->addToRecipientsList(['users_id' => $id, 'language' => 'de_DE']);
            $this->boolean($target->addToRecipientsList(['users_id' => $id]))->isFalse();
            $this->array($seen)->hasSize(2);
            $this->string($seen[0]['username'])->isIdenticalTo(formatUserName(0, $user->fields['name'], 'Recipient', 'Before', 0, 0, true));
            $this->string($seen[1]['username'])->isIdenticalTo(formatUserName(0, $user->fields['name'], 'Recipient', 'After', 0, 0, true));
            $this->array($seen[0]['additionnaloption'])->isIdenticalTo(['usertype' => LegacyNotificationTarget::GLPI_USER, 'timezone' => 'Europe/Paris']);
            $this->array($seen[1]['additionnaloption'])->isIdenticalTo(['usertype' => LegacyNotificationTarget::EXTERNAL_USER]);
            $this->array(array_column($seen, 'language'))->isIdenticalTo(['fr_FR', 'de_DE']);
            $this->boolean(isset($target->recipient_data))->isFalse();
            $this->boolean($DB->update('glpi_users', ['is_active' => 1], ['id' => $id]))->isTrue();
            $target->addToRecipientsList(['users_id' => $id, 'name' => 'Explicit recipient', 'usertype' => LegacyNotificationTarget::ANONYMOUS_USER]);
            $this->string($target->target[$id]['username'])->isIdenticalTo('Explicit recipient');
            $this->integer($target->target[$id]['additionnaloption']['usertype'])->isIdenticalTo(LegacyNotificationTarget::ANONYMOUS_USER);
            $this->object($DB->getDoctrineConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $em?->clear();
            $_SESSION = $session;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
    }

    public function testAuthorAndTaskRecipientLocalesStayCurrent(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        try {
            $this->login();
            $this->setEntity('_test_root_entity', false);
            $entity = (int)$_SESSION['glpiactive_entity'];
            $user = $this->createItem(User::class, ['name' => 'Author locale ' . $this->getUniqueString(),
                'entities_id' => $entity, 'authtype' => Auth::DB_GLPI, 'is_active' => 1, 'language' => 'en_GB']);
            $id = (int)$user->getID();
            $this->createItem(Profile_User::class, ['users_id' => $id,
                'profiles_id' => $_SESSION['glpiactiveprofile']['id'], 'entities_id' => $entity, 'is_recursive' => 0]);
            $reminder = $this->createItem(Reminder::class, ['name' => 'Locale reminder ' . $this->getUniqueString(),
                'users_id' => $id, 'text' => 'Recipient locale fixture']);
            $target = new NotificationTargetPlanningRecall($entity);
            $target->setEvent(NotificationEventAjax::class);
            $seen = [];
            $plugins->setValue(null, [...$active, 'recipient_locale_fixture']);
            $PLUGIN_HOOKS['add_recipient_to_target'] = ['recipient_locale_fixture' => [
                NotificationTargetPlanningRecall::class => function (LegacyNotificationTarget $delivery) use (&$seen, $DB, $id): void {
                    $seen[] = $delivery->target[$id]['language'];
                    $this->boolean($DB->update('glpi_users', ['language' => count($seen) === 1 ? 'fr_FR' : 'de_DE'], ['id' => $id]))->isTrue();
                },
            ]];
            $target->obj = $reminder;
            $target->addItemAuthor();
            $target->obj = (object)['fields' => ['itemtype' => Reminder::class, 'items_id' => $reminder->getID()]];
            $target->addTaskAssignUser();
            $target->obj = $reminder;
            $target->addItemAuthor();
            $this->array($seen)->isIdenticalTo(['en_GB', 'fr_FR', 'de_DE']);
            $this->boolean($DB->update('glpi_users', ['is_active' => 0], ['id' => $id]))->isTrue();
            $target->addItemAuthor();
            $this->array($seen)->hasSize(3);
            foreach ([null, '', PHP_INT_MAX] as $missing) {
                $reminder->fields['users_id'] = $missing;
                $target->addItemAuthor();
            }
            $this->array($seen)->hasSize(3);
        } finally {
            $_SESSION = $session;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
    }

    public function testPlanningGuestLanguageKeepsRecipientCallbackFreshness(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', false);
        $em = Orm::create($DB);
        try {
            $root = $em->getReference(Entity::class, (int)$_SESSION['glpiactive_entity']);
            $users = [];
            foreach (['en_GB', 'fr_FR', 'de_DE'] as $language) {
                $user = new UserEntity();
                $user->entities = $root;
                $user->name = 'Recall locale ' . $this->getUniqueString();
                $user->language = $language;
                $em->persist($user);
                $users[] = $user;
            }
            $em->flush();
            [$first, $second, $missing] = array_map(static fn ($user): int => (int)$user->id, $users);
            $em->remove($users[2]);
            $em->flush();
            $em->clear();
            $loads = new class () {
                public int $count = 0;
                public function postLoad(): void
                {
                    ++$this->count;
                }
            };
            $em->getEventManager()->addEventListener([Events::postLoad], $loads);
            $repository = new NotificationRecipientRepository($em);
            $this->array($repository->guestLanguage($first))->isIdenticalTo(['language' => 'en_GB', 'users_id' => $first]);
            $this->variable($repository->guestLanguage($missing))->isNull();
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->integer(countElementsInTable('glpi_profiles_users', ['users_id' => [$first, $second]]))->isIdenticalTo(0);

            // An extended event can provide duplicates and a currently missing
            // identity. Its recipient callback must run before the next read.
            $item = new class () extends PlanningExternalEvent {
                public static array $guests = [];
                public function getFromDB($id)
                {
                    $this->fields = ['id' => $id, 'users_id_guests' => self::$guests];
                    return true;
                }
            };
            $item::$guests = [$first, $missing, $second, $first, PHP_INT_MAX, null, ''];
            $target = new class () extends NotificationTargetPlanningRecall {
                public array $seen = [];
                public $onRecipient;
                public function addToRecipientsList(array $data)
                {
                    $this->seen[] = $data;
                    ($this->onRecipient)(count($this->seen));
                }
            };
            $target->obj = (object)['fields' => ['itemtype' => $item::class, 'items_id' => 1]];
            $target->onRecipient = function (int $count) use ($DB, $first, $second, $missing): void {
                if ($count !== 1) {
                    return;
                }
                $this->boolean($DB->update('glpi_users', ['language' => 'it_IT'], ['id' => $second]))->isTrue();
                $this->boolean($DB->update('glpi_users', ['language' => 'es_ES'], ['id' => $first]))->isTrue();
                $writer = Orm::create($DB);
                try {
                    $this->integer((new RecordWriter($writer))->insert('glpi_users', [
                        'id' => $missing, 'name' => 'Created during recall ' . $this->getUniqueString(),
                        'entities_id' => (int)$_SESSION['glpiactive_entity'], 'language' => 'de_DE',
                    ]))->isIdenticalTo($missing);
                } finally {
                    $writer->clear();
                }
            };
            $target->addSpecificTargets(['type' => Notification::USER_TYPE, 'items_id' => Notification::PLANNING_EVENT_GUESTS], []);
            $this->array($target->seen)->isIdenticalTo([
                ['language' => 'en_GB', 'users_id' => $first],
                ['language' => 'de_DE', 'users_id' => $missing],
                ['language' => 'it_IT', 'users_id' => $second],
                ['language' => 'es_ES', 'users_id' => $first],
            ]);
            // The preliminary projection does not grant notification access.
            $delivery = new NotificationTargetPlanningRecall();
            $this->boolean($delivery->addToRecipientsList($target->seen[0]))->isFalse();
            $this->array($delivery->target)->isEmpty();
        } finally {
            $em->clear();
        }
    }

    public function testShowForNotificationPreselectsExistingTargets()
    {
        $this->login();

        $notif = new \Notification();
        $this->boolean($notif->getFromDB(1))->isTrue();
        $group = (new Group())->add(['name' => 'Notification preselection fixture']);
        $this->integer($group)->isGreaterThan(0);

        \NotificationTarget::updateTargets([
           'notifications_id' => $notif->getID(),
           'itemtype'         => $notif->getField('itemtype'),
           '_targets'         => ['1_1', '3_' . $group],
        ]);

        $target = \NotificationTarget::getInstanceByType(
            $notif->getField('itemtype'),
            $notif->getField('event'),
            ['entities_id' => $notif->getField('entities_id')]
        );
        $this->object($target)->isInstanceOf(\NotificationTarget::class);

        $this->output(
            function () use ($target, $notif) {
                $target->showForNotification($notif);
            }
        )->contains('"1_1":"1_1"')
          ->contains('"3_' . $group . '":"3_' . $group . '"')
          ->notContains('value="Array"')
          ->notContains('JSON.parse(\'"{\\\"');
    }

    public function testUpdateTargetsKeepsPostedTargets()
    {
        $this->login();

        $notif = new \Notification();
        $this->boolean($notif->getFromDB(1))->isTrue();
        $group = (new Group())->add(['name' => 'Notification posted target fixture']);
        $this->integer($group)->isGreaterThan(0);

        \NotificationTarget::updateTargets([
           'notifications_id' => $notif->getID(),
           'itemtype'         => $notif->getField('itemtype'),
           '_targets'         => ['1_1', '3_' . $group],
        ]);

        $this->integer(countElementsInTable(\NotificationTarget::getTable(), [
           'notifications_id' => $notif->getID(),
           'type'             => 1,
           'items_id'         => 1,
        ]))->isIdenticalTo(1);

        $this->integer(countElementsInTable(\NotificationTarget::getTable(), [
           'notifications_id' => $notif->getID(),
           'type'             => 3,
           'items_id'         => $group,
        ]))->isIdenticalTo(1);
    }

    public function testGetSubjectPrefix()
    {
        $this->login();

        $root    = getItemByTypeName('Entity', 'Root entity', true);
        $parent  = getItemByTypeName('Entity', '_test_root_entity', true);
        $child_1 = getItemByTypeName('Entity', '_test_child_1', true);
        $child_2 = getItemByTypeName('Entity', '_test_child_2', true);

        $ntarget_parent  = new \NotificationTarget($parent);
        $ntarget_child_1 = new \NotificationTarget($child_1);
        $ntarget_child_2 = new \NotificationTarget($child_2);

        $this->string($ntarget_parent->getSubjectPrefix())->isEqualTo("[ITSM-NG] ");
        $this->string($ntarget_child_1->getSubjectPrefix())->isEqualTo("[ITSM-NG] ");
        $this->string($ntarget_child_2->getSubjectPrefix())->isEqualTo("[ITSM-NG] ");

        $entity  = new \Entity();
        $this->boolean($entity->update([
           'id'                       => $root,
           'notification_subject_tag' => "prefix_root",
        ]))->isTrue();

        $this->string($ntarget_parent->getSubjectPrefix())->isEqualTo("[prefix_root] ");
        $this->string($ntarget_child_1->getSubjectPrefix())->isEqualTo("[prefix_root] ");
        $this->string($ntarget_child_2->getSubjectPrefix())->isEqualTo("[prefix_root] ");

        $this->boolean($entity->update([
           'id'                       => $parent,
           'notification_subject_tag' => "prefix_parent",
        ]))->isTrue();

        $this->string($ntarget_parent->getSubjectPrefix())->isEqualTo("[prefix_parent] ");
        $this->string($ntarget_child_1->getSubjectPrefix())->isEqualTo("[prefix_parent] ");
        $this->string($ntarget_child_2->getSubjectPrefix())->isEqualTo("[prefix_parent] ");

        $this->boolean($entity->update([
           'id'                       => $child_1,
           'notification_subject_tag' => "prefix_child_1",
        ]))->isTrue();

        $this->string($ntarget_parent->getSubjectPrefix())->isEqualTo("[prefix_parent] ");
        $this->string($ntarget_child_1->getSubjectPrefix())->isEqualTo("[prefix_child_1] ");
        $this->string($ntarget_child_2->getSubjectPrefix())->isEqualTo("[prefix_parent] ");

        $this->boolean($entity->update([
           'id'                       => $child_2,
           'notification_subject_tag' => "prefix_child_2",
        ]))->isTrue();

        $this->string($ntarget_parent->getSubjectPrefix())->isEqualTo("[prefix_parent] ");
        $this->string($ntarget_child_1->getSubjectPrefix())->isEqualTo("[prefix_child_1] ");
        $this->string($ntarget_child_2->getSubjectPrefix())->isEqualTo("[prefix_child_2] ");
    }

    public function testGetReplyTo()
    {
        global $CFG_GLPI;

        $this->login();

        $root    = getItemByTypeName('Entity', 'Root entity', true);
        $parent  = getItemByTypeName('Entity', '_test_root_entity', true);
        $child_1 = getItemByTypeName('Entity', '_test_child_1', true);
        $child_2 = getItemByTypeName('Entity', '_test_child_2', true);

        $ntarget_parent  = new \NotificationTarget($parent);
        $ntarget_child_1 = new \NotificationTarget($child_1);
        $ntarget_child_2 = new \NotificationTarget($child_2);

        // test global settings
        $CFG_GLPI['admin_reply'] = 'test@global.tld';
        $CFG_GLPI['admin_reply_name'] = 'test global';
        $CFG_GLPI['from_email'] = '';

        $this->array($ntarget_parent->getReplyTo())->isEqualTo([
           'email' => 'test@global.tld',
           'name'  => 'test global'
        ]);
        $this->array($ntarget_child_1->getReplyTo())->isEqualTo([
           'email' => 'test@global.tld',
           'name'  => 'test global'
        ]);
        $this->array($ntarget_child_2->getReplyTo())->isEqualTo([
           'email' => 'test@global.tld',
           'name'  => 'test global'
        ]);

        // test root entity settings
        $entity  = new \Entity();
        $this->boolean($entity->update([
           'id'               => $root,
           'admin_reply'      => "test@root.tld",
           'admin_reply_name' => "test root",
        ]))->isTrue();

        $this->array($ntarget_parent->getReplyTo())->isEqualTo([
           'email' => 'test@root.tld',
           'name'  => 'test root'
        ]);
        $this->array($ntarget_child_1->getReplyTo())->isEqualTo([
           'email' => 'test@root.tld',
           'name'  => 'test root'
        ]);
        $this->array($ntarget_child_2->getReplyTo())->isEqualTo([
           'email' => 'test@root.tld',
           'name'  => 'test root'
        ]);

        // test parent entity settings
        $this->boolean($entity->update([
           'id'               => $parent,
           'admin_reply'      => "test@parent.tld",
           'admin_reply_name' => "test parent",
        ]))->isTrue();

        $this->array($ntarget_parent->getReplyTo())->isEqualTo([
           'email' => 'test@parent.tld',
           'name'  => 'test parent'
        ]);
        $this->array($ntarget_child_1->getReplyTo())->isEqualTo([
           'email' => 'test@parent.tld',
           'name'  => 'test parent'
        ]);
        $this->array($ntarget_child_2->getReplyTo())->isEqualTo([
           'email' => 'test@parent.tld',
           'name'  => 'test parent'
        ]);

        // test child_1 entity settings
        $this->boolean($entity->update([
           'id'               => $child_1,
           'admin_reply'      => "test@child1.tld",
           'admin_reply_name' => "test child1",
        ]))->isTrue();

        $this->array($ntarget_parent->getReplyTo())->isEqualTo([
           'email' => 'test@parent.tld',
           'name'  => 'test parent'
        ]);
        $this->array($ntarget_child_1->getReplyTo())->isEqualTo([
           'email' => 'test@child1.tld',
           'name'  => 'test child1'
        ]);
        $this->array($ntarget_child_2->getReplyTo())->isEqualTo([
           'email' => 'test@parent.tld',
           'name'  => 'test parent'
        ]);

        // test child_2 entity settings
        $this->boolean($entity->update([
           'id'               => $child_2,
           'admin_reply'      => "test@child2.tld",
           'admin_reply_name' => "test child2",
        ]))->isTrue();

        $this->array($ntarget_parent->getReplyTo())->isEqualTo([
           'email' => 'test@parent.tld',
           'name'  => 'test parent'
        ]);
        $this->array($ntarget_child_1->getReplyTo())->isEqualTo([
           'email' => 'test@child1.tld',
           'name'  => 'test child1'
        ]);
        $this->array($ntarget_child_2->getReplyTo())->isEqualTo([
           'email' => 'test@child2.tld',
           'name'  => 'test child2'
        ]);

    }
}
