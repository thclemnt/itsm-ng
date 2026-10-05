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
use itsmng\Database\Orm;
use itsmng\Database\Entity;
use itsmng\Database\Repository\NotificationRecipientRepository;

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
            foreach ([[$ticket, null, \CommonITILActor::REQUESTER, true, $email],
                [$ticket, null, \CommonITILActor::REQUESTER, true, 'not-an-address'],
                [$ticket, null, \CommonITILActor::REQUESTER, true, null],
                [$ticket, null, \CommonITILActor::REQUESTER, false, 'disabled@example.test'],
                [$ticket, null, \CommonITILActor::OBSERVER, true, 'observer@example.test'],
                [$peer, null, \CommonITILActor::REQUESTER, true, $email],
                [$ticket, $user, \CommonITILActor::REQUESTER, true, 'registered@example.test']] as [$parent, $actor, $role, $notify, $address]) {
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
            $loads = new class {
                public int $count = 0;
                public function postLoad(\Doctrine\ORM\Event\PostLoadEventArgs $event): void
                {
                    ++$this->count;
                }
            };
            $manager->getEventManager()->addEventListener(['postLoad'], $loads);
            $repository = new NotificationRecipientRepository($manager);
            $rows = $repository->anonymousUsers('glpi_tickets_users', 'tickets_id', $ticket->id, \CommonITILActor::REQUESTER);
            $this->array($rows)->hasSize(3);
            foreach ([['alternative_email' => $email], ['alternative_email' => 'not-an-address'], ['alternative_email' => null]] as $expected) {
                $this->boolean(in_array($expected, $rows, true))->isTrue();
            }
            $this->integer($manager->getUnitOfWork()->size())->isEqualTo(0);
            $this->array($repository->anonymousUsers('glpi_tickets_users', 'tickets_id', 0, \CommonITILActor::REQUESTER))->isEmpty();
            $this->array($repository->anonymousUsers('glpi_tickets_users', 'tickets_id', $ticket->id, \CommonITILActor::OBSERVER))
                ->isIdenticalTo([['alternative_email' => 'observer@example.test']]);

            $model = new \Ticket();
            $this->boolean($model->getFromDB($ticket->id))->isTrue();
            $target = new \NotificationTargetTicket($root->id, 'new', $model);
            $target->setMode(\Notification_NotificationTemplate::MODE_MAIL)->setEvent(\NotificationEventMailing::class);
            $target->addLinkedUserByType(\CommonITILActor::REQUESTER);
            $this->array(array_keys($target->target))->isIdenticalTo([$email]);
            $this->variable($target->target[$email]['users_id'])->isIdenticalTo(-1);
            $this->string($target->target[$email]['email'])->isIdenticalTo($email);
            $this->string($target->target[$email]['language'])->isIdenticalTo($CFG_GLPI['language']);
            $this->integer($target->target[$email]['additionnaloption']['usertype'])->isIdenticalTo(\NotificationTarget::ANONYMOUS_USER);
            $target->addLinkedUserByType(\CommonITILActor::REQUESTER);
            $this->array(array_keys($target->target))->isIdenticalTo([$email]);

            $writer = Orm::create($DB);
            $writer->find(Entity\TicketUser::class, $actors[0]->id)->alternative_email = 'fresh@example.test';
            $writer->flush();
            $writer->clear();
            $fresh = $repository->anonymousUsers('glpi_tickets_users', 'tickets_id', $ticket->id, \CommonITILActor::REQUESTER);
            $this->boolean(in_array(['alternative_email' => 'fresh@example.test'], $fresh, true))->isTrue();
            $this->boolean(in_array(['alternative_email' => $email], $fresh, true))->isFalse();
            $this->integer($manager->getUnitOfWork()->size())->isEqualTo(0);
            $target->target = [];
            $target->addLinkedUserByType(\CommonITILActor::REQUESTER);
            $this->array(array_keys($target->target))->isIdenticalTo(['fresh@example.test']);
            $nonMail = new \NotificationTargetTicket($root->id, 'new', $model);
            $nonMail->setMode(\Notification_NotificationTemplate::MODE_AJAX)->setEvent(\NotificationEventAjax::class);
            $nonMail->addLinkedUserByType(\CommonITILActor::REQUESTER);
            $this->array($nonMail->target)->isEmpty();
            $this->integer($loads->count)->isEqualTo(0);
            $this->object($manager->find(Entity\TicketUser::class, $actors[0]->id))->isInstanceOf(Entity\TicketUser::class);
            $this->integer($loads->count)->isGreaterThan(0);
        } finally {
            $manager->clear();
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
