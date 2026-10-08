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
use CommonITILActor;
use DbTestCase;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use NotificationEventAjax;
use NotificationEventMailing;
use NotificationTarget;
use NotificationTargetTicket as LegacyNotificationTargetTicket;
use Notification_NotificationTemplate;
use Ticket;
use Ticket_User;
use User;
use itsmng\Database\Orm;
use itsmng\Database\Entity;
use itsmng\Database\Repository\NotificationRecipientRepository;
use itsmng\Database\Repository\UserRepository;

/* Test for inc/notificationtargetticket.class.php */

class NotificationTargetTicket extends DbTestCase
{
    public function testAnonymousRecipientProjectionKeepsPublicValidation(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $this->setEntity('_test_root_entity', false);
        $manager = Orm::create($DB);
        try {
            $root = $manager->find(Entity\Entity::class, $_SESSION['glpiactive_entity']);
            $ticket = new Entity\Ticket();
            $ticket->entities = $root;
            $ticket->name = 'Anonymous choices ' . $this->getUniqueString();
            $peer = new Entity\Ticket();
            $peer->entities = $root;
            $peer->name = $ticket->name . ' peer';
            $user = new Entity\User();
            $user->entities = $root;
            $user->name = 'No profile ' . $this->getUniqueString();
            foreach ([$ticket, $peer, $user] as $record) {
                $manager->persist($record);
            }
            $email = "o'connor@example.test";
            $actors = [];
            foreach ([[$ticket, null, CommonITILActor::REQUESTER, true, $email],
                [$ticket, null, CommonITILActor::REQUESTER, true, 'not-an-address'],
                [$ticket, null, CommonITILActor::REQUESTER, true, null],
                [$ticket, null, CommonITILActor::REQUESTER, false, 'disabled@example.test'],
                [$ticket, null, CommonITILActor::OBSERVER, true, 'observer@example.test'],
                [$peer, null, CommonITILActor::REQUESTER, true, $email],
                [$ticket, $user, CommonITILActor::REQUESTER, true, 'registered@example.test']] as [$parent, $actor, $role, $notify, $address]) {
                $link = new Entity\TicketUser();
                $link->tickets = $parent;
                $link->actor = $actor;
                $link->type = $role;
                $link->use_notification = $notify;
                $link->alternative_email = $address;
                $manager->persist($link);
                $actors[] = $link;
            }
            $manager->flush();
            $manager->clear();
            $loads = new class () {
                public int $count = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    ++$this->count;
                }
            };
            $manager->getEventManager()->addEventListener(['postLoad'], $loads);
            $repository = new NotificationRecipientRepository($manager);
            $rows = $repository->anonymousUsers('glpi_tickets_users', 'tickets_id', $ticket->id, CommonITILActor::REQUESTER);
            $this->array($rows)->hasSize(3);
            foreach ([['alternative_email' => $email], ['alternative_email' => 'not-an-address'], ['alternative_email' => null]] as $expected) {
                $this->boolean(in_array($expected, $rows, true))->isTrue();
            }
            $this->integer($manager->getUnitOfWork()->size())->isEqualTo(0);
            $this->array($repository->anonymousUsers('glpi_tickets_users', 'tickets_id', 0, CommonITILActor::REQUESTER))->isEmpty();
            $this->array($repository->anonymousUsers('glpi_tickets_users', 'tickets_id', $ticket->id, CommonITILActor::OBSERVER))
                ->isIdenticalTo([['alternative_email' => 'observer@example.test']]);

            $model = new Ticket();
            $this->boolean($model->getFromDB($ticket->id))->isTrue();
            $target = new LegacyNotificationTargetTicket($root->id, 'new', $model);
            $target->setMode(Notification_NotificationTemplate::MODE_MAIL)->setEvent(NotificationEventMailing::class);
            $target->addLinkedUserByType(CommonITILActor::REQUESTER);
            $this->array(array_keys($target->target))->isIdenticalTo([$email]);
            $this->variable($target->target[$email]['users_id'])->isIdenticalTo(-1);
            $this->string($target->target[$email]['email'])->isIdenticalTo($email);
            $this->string($target->target[$email]['language'])->isIdenticalTo($CFG_GLPI['language']);
            $this->integer($target->target[$email]['additionnaloption']['usertype'])->isIdenticalTo(NotificationTarget::ANONYMOUS_USER);
            $target->addLinkedUserByType(CommonITILActor::REQUESTER);
            $this->array(array_keys($target->target))->isIdenticalTo([$email]);

            $writer = Orm::create($DB);
            $writer->find(Entity\TicketUser::class, $actors[0]->id)->alternative_email = 'fresh@example.test';
            $writer->flush();
            $writer->clear();
            $fresh = $repository->anonymousUsers('glpi_tickets_users', 'tickets_id', $ticket->id, CommonITILActor::REQUESTER);
            $this->boolean(in_array(['alternative_email' => 'fresh@example.test'], $fresh, true))->isTrue();
            $this->boolean(in_array(['alternative_email' => $email], $fresh, true))->isFalse();
            $this->integer($manager->getUnitOfWork()->size())->isEqualTo(0);
            $target->target = [];
            $target->addLinkedUserByType(CommonITILActor::REQUESTER);
            $this->array(array_keys($target->target))->isIdenticalTo(['fresh@example.test']);
            $nonMail = new LegacyNotificationTargetTicket($root->id, 'new', $model);
            $nonMail->setMode(Notification_NotificationTemplate::MODE_AJAX)->setEvent(NotificationEventAjax::class);
            $nonMail->addLinkedUserByType(CommonITILActor::REQUESTER);
            $this->array($nonMail->target)->isEmpty();
            $this->integer($loads->count)->isEqualTo(0);
            $this->object($manager->find(Entity\TicketUser::class, $actors[0]->id))->isInstanceOf(Entity\TicketUser::class);
            $this->integer($loads->count)->isGreaterThan(0);
        } finally {
            $manager->clear();
        }
    }

    public function testTemplateFriendlyNamesKeepActorOrderAndCurrentReads(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $session = $_SESSION;
        $idsVisible = $CFG_GLPI['is_ids_visible'];
        $entity = (int)$_SESSION['glpiactive_entity'];
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $em = Orm::create($DB);
        $loads = new class () {
            public int $count = 0;
            public function postLoad(): void
            {
                ++$this->count;
            }
        };
        $em->getEventManager()->addEventListener([Events::postLoad], $loads);
        try {
            $prefix = 'Notification names ' . $this->getUniqueString();
            $first = $this->createItem(User::class, ['name' => $prefix . ' first',
                'firstname' => 'Ada', 'realname' => 'Reader', 'entities_id' => $entity, 'authtype' => Auth::DB_GLPI]);
            $second = $this->createItem(User::class, ['name' => $prefix . ' second',
                'firstname' => '', 'realname' => '', 'entities_id' => $entity, 'authtype' => Auth::DB_GLPI]);
            $a = (int)$first->getID();
            $b = (int)$second->getID();
            $ticket = new Ticket();
            $id = $ticket->add(['name' => $prefix, 'content' => 'Template name projection',
                'entities_id' => $entity, 'users_id_recipient' => $a]);
            $this->integer($id)->isGreaterThan(0);
            foreach ([$b, $a] as $actor) {
                $link = new Ticket_User();
                $this->integer($link->add(['tickets_id' => $id, 'users_id' => $actor,
                    'type' => CommonITILActor::ASSIGN, 'use_notification' => 0]))->isGreaterThan(0);
            }
            $this->boolean($ticket->getFromDB($id))->isTrue();
            $target = new LegacyNotificationTargetTicket($entity, 'new', $ticket);
            $options = ['additionnaloption' => ['usertype' => '']];
            $repository = new UserRepository($em);
            $rows = $repository->friendlyNameData([$a, $b, $a, PHP_INT_MAX]);
            $this->array($rows)->hasSize(2);
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
            $managed = $em->find(Entity\User::class, $a);
            $this->integer($loads->count)->isIdenticalTo(1);
            $this->boolean($DB->update('glpi_users', ['firstname' => 'Grace'], ['id' => $a]))->isTrue();
            $this->string($repository->friendlyNameData([$a])[$a]['firstname'])->isIdenticalTo('Grace');
            $this->string($managed->firstname)->isIdenticalTo('Ada');
            $this->integer($loads->count)->isIdenticalTo(1);
            $this->boolean($em->contains($managed))->isTrue();
            foreach ([User::FIRSTNAME_BEFORE, User::REALNAME_BEFORE] as $format) {
                $_SESSION['glpinames_format'] = $format;
                $_SESSION['glpiis_ids_visible'] = $CFG_GLPI['is_ids_visible'] = 1;
                $expected = [];
                foreach ($ticket->getUsers(CommonITILActor::ASSIGN) as $actor) {
                    $user = new User();
                    $this->boolean($user->getFromDB($actor['users_id']))->isTrue();
                    $expected[$actor['users_id']] = $user->getName();
                }
                $data = $target->getDataForObject($ticket, $options, true);
                $this->string($data['##ticket.assigntousers##'])->isIdenticalTo(implode(', ', $expected));
                foreach (['users_id_recipient' => 'openbyuser', 'users_id_lastupdater' => 'lastupdater'] as $field => $tag) {
                    $user = new User();
                    $user->getFromDB($ticket->getField($field));
                    $this->string($data['##ticket.' . $tag . '##'])
                        ->isIdenticalTo($ticket->getField($field) ? $user->getName() : '');
                }
                $this->integer($_SESSION['glpiis_ids_visible'])->isIdenticalTo(1);
                $this->integer($CFG_GLPI['is_ids_visible'])->isIdenticalTo(1);
            }
            // A custom selected-actor callback remains a freshness barrier. It can
            // supply duplicates or a disappeared identity without changing row order.
            $selected = new class () extends Ticket {
                public array $selected = [];
                public $beforeSelection;
                public static function getType()
                {
                    return 'Ticket';
                }
                public function countUsers($type = 0)
                {
                    return $type === CommonITILActor::ASSIGN ? count($this->selected) : 0;
                }
                public function getUsers($type)
                {
                    if ($type !== CommonITILActor::ASSIGN) {
                        return [];
                    }
                    ($this->beforeSelection)();
                    return $this->selected;
                }
            };
            $selected->fields = $ticket->fields;
            $selected->fields['users_id_recipient'] = $a;
            $selected->fields['users_id_lastupdater'] = PHP_INT_MAX;
            $selected->selected = [['users_id' => $b], ['users_id' => PHP_INT_MAX], ['users_id' => $a], ['users_id' => $b]];
            $selected->beforeSelection = static function () use ($connection, $a): void {
                $connection->update('glpi_users', ['firstname' => 'Katherine'], ['id' => $a]);
            };
            $before = new User();
            $this->boolean($before->getFromDB($a))->isTrue();
            $data = $target->getDataForObject($selected, $options, true);
            $this->string($data['##ticket.openbyuser##'])->isIdenticalTo($before->getName());
            $this->string($data['##ticket.lastupdater##'])->isIdenticalTo(NOT_AVAILABLE);
            $this->boolean($first->getFromDB($a))->isTrue();
            $this->boolean($second->getFromDB($b))->isTrue();
            $this->string($data['##ticket.assigntousers##'])->isIdenticalTo($second->getName() . ', ' . $first->getName());
            $selected->fields['users_id_recipient'] = null;
            $selected->fields['users_id_lastupdater'] = null;
            $selected->selected = [];
            $data = $target->getDataForObject($selected, $options, true);
            foreach (['openbyuser', 'lastupdater', 'assigntousers'] as $tag) {
                $this->string($data['##ticket.' . $tag . '##'])->isIdenticalTo('');
            }
            $this->object($em->getConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $em->getEventManager()->removeEventListener([Events::postLoad], $loads);
            $em->clear();
            $_SESSION = $session;
            $CFG_GLPI['is_ids_visible'] = $idsVisible;
        }
    }

    public function testgetDataForObject()
    {
        global $CFG_GLPI;

        $tkt = getItemByTypeName('Ticket', '_ticket01');
        $notiftargetticket = new \NotificationTargetTicket(getItemByTypeName('Entity', '_test_root_entity', true), 'new', $tkt);
        $notiftargetticket->getTags();

        // basic test for ##task.categorycomment## tag
        $expected = [
           'tag'             => 'task.categorycomment',
           'value'           => true,
           'label'           => 'Category comment',
           'events'          => 0,
           'foreach'         => false,
           'lang'            => true,
           'allowed_values'  => [],
           ];

        $this->array($notiftargetticket->tag_descriptions['lang']['##lang.task.categorycomment##'])
           ->isIdenticalTo($expected);
        $this->array($notiftargetticket->tag_descriptions['tag']['##task.categorycomment##'])
           ->isIdenticalTo($expected);

        // basic test for ##task.categorid## tag
        $expected = [
           'tag'             => 'task.categoryid',
           'value'           => true,
           'label'           => 'Category id',
           'events'          => 0,
           'foreach'         => false,
           'lang'            => true,
           'allowed_values'  => [],
           ];
        $this->array($notiftargetticket->tag_descriptions['lang']['##lang.task.categoryid##'])
           ->isIdenticalTo($expected);
        $this->array($notiftargetticket->tag_descriptions['tag']['##task.categoryid##'])
           ->isIdenticalTo($expected);

        // advanced test for ##task.categorycomment## and ##task.categoryid## tags
        // test of the getDataForObject for default language en_GB
        $taskcat = getItemByTypeName('TaskCategory', '_subcat_1');
        $encoded_sep = \Toolbox::clean_cross_side_scripting_deep('>');
        $expected = [
                       [
                       '##task.id##'              => 1,
                       '##task.isprivate##'       => 'No',
                       '##task.author##'          => '_test_user',
                       '##task.categoryid##'      => $taskcat->getID(),
                       '##task.category##'        => '_cat_1 ' . $encoded_sep . ' _subcat_1',
                       '##task.categorycomment##' => 'Comment for sub-category _subcat_1',
                       '##task.date##'            => '2016-10-19 11:50',
                       '##task.description##'     => 'Task to be done',
                       '##task.time##'            => '0 seconds',
                       '##task.status##'          => 'To do',
                       '##task.user##'            => '_test_user',
                       '##task.group##'           => '',
                       '##task.begin##'           => '',
                       '##task.end##'             => ''
                       ]
                    ];

        $basic_options = [
           'additionnaloption' => [
              'usertype' => ''
           ]
        ];
        $ret = $notiftargetticket->getDataForObject($tkt, $basic_options);

        $this->array($ret['tasks'])->hasSize(1);
        $this->array($ret['tasks'][0])->hasKeys(array_keys($expected[0]));
        $this->string((string)$ret['tasks'][0]['##task.categorycomment##'])->contains('_subcat_1');

        // test of the getDataForObject for default language fr_FR
        $CFG_GLPI['translate_dropdowns'] = 1;
        $_SESSION["glpilanguage"] = \Session::loadLanguage('fr_FR');
        $_SESSION['glpi_dropdowntranslations'] = \DropdownTranslation::getAvailableTranslations($_SESSION["glpilanguage"]);

        $ret = $notiftargetticket->getDataForObject($tkt, $basic_options);

        $expected = [
                       [
                       '##task.id##'              => 1,
                       '##task.isprivate##'       => 'Non',
                       '##task.author##'          => '_test_user',
                       '##task.categoryid##'      => $taskcat->getID(),
                       '##task.category##'        => 'FR - _cat_1 ' . $encoded_sep . ' FR - _subcat_1',
                       '##task.categorycomment##' => 'FR - Commentaire pour sous-catégorie _subcat_1',
                       '##task.date##'            => '2016-10-19 11:50',
                       '##task.description##'     => 'Task to be done',
                       '##task.time##'            => '0 seconde',
                       '##task.status##'          => 'A faire',
                       '##task.user##'            => '_test_user',
                       '##task.group##'           => '',
                       '##task.begin##'           => '',
                       '##task.end##'             => ''
                       ]
                    ];

        $this->array($ret['tasks'])->hasSize(1);
        $this->array($ret['tasks'][0])->hasKeys(array_keys($expected[0]));
        $this->string((string)$ret['tasks'][0]['##task.categorycomment##'])->contains('_subcat_1');

        // switch back to default language
        $_SESSION["glpilanguage"] = \Session::loadLanguage('en_GB');
    }
}
