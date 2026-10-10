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

use CommonDBTM as LegacyCommonDBTM;
use DateTime;
use DateTimeImmutable;
use DbTestCase;
use Doctrine\ORM\EntityManager;
use ReflectionProperty;
use NotificationAjax as LegacyNotificationAjax;
use Session;
use itsmng\Database\Entity\QueuedNotification;
use itsmng\Database\Orm;
use itsmng\Domain\BrowserNotificationInbox;
use itsmng\Domain\BrowserNotificationMessage;

/* Test for inc/notificationajax.class.php .class.php */

class NotificationAjax extends DbTestCase
{
    public function testAcknowledgementPersistsItsNativeTimestampAndOnlyRecipientOwnedFields(): void
    {
        global $DB, $CFG_GLPI;

        $savedSession = $_SESSION;
        $savedConfig = $CFG_GLPI;
        try {
            $this->login();
            $recipient = (int)Session::getLoginUserID();
            $this->integer($recipient)->isGreaterThan(0);
            $message = $this->createItem('QueuedNotification', [
                'mode' => 'ajax', 'recipient' => (string)$recipient,
                'entities_id' => 0, 'itemtype' => 'NotificationAjax',
                'name' => 'browser-clock-' . $this->getUniqueString(),
                'body_text' => 'Preserve this rendered body',
                'create_time' => '2010-01-01 00:00:00',
                'send_time' => '2037-01-01 00:00:00', 'sent_time' => null,
            ]);
            $id = (int)$message->getID();
            $connection = $DB->getDoctrineConnection();
            $native = static fn (): array => $connection->fetchAssociative('SELECT * FROM glpi_queuednotifications WHERE id = ?', [$id]);
            // The queue's public add lifecycle always starts with zero tries.
            // Establish an existing retry count through its public update before
            // removing queue-administration rights for the recipient operation.
            $this->integer((int)$native()['sent_try'])->isIdenticalTo(0);
            $this->boolean($message->update(['id' => $id, 'sent_try' => 4]))->isTrue();
            $before = $native();
            $this->integer((int)$before['sent_try'])->isIdenticalTo(4);
            // Presentation belongs to the recipient, independent of queue
            // administration rights, active entity and the scheduled mail clock.
            $_SESSION['glpiactiveprofile']['queuednotification'] = 0;
            $_SESSION['glpiactiveentities'] = [];
            $CFG_GLPI['notifications_ajax'] = true;
            $inbox = new BrowserNotificationInbox($DB);
            $this->boolean($inbox->acknowledge($id, $recipient + 1))->isFalse();
            $this->array($native())->isIdenticalTo($before);
            LegacyNotificationAjax::raisedNotification($id);
            $after = $native();
            $this->boolean((bool)$after['is_deleted'])->isTrue();
            $this->variable($after['sent_time'])->isNotNull();
            $unchanged = $after;
            $unchanged['sent_time'] = $before['sent_time'];
            $unchanged['is_deleted'] = $before['is_deleted'];
            $this->array($unchanged)->isIdenticalTo($before);
            LegacyNotificationAjax::raisedNotification($id);
            $this->array($native())->isIdenticalTo($after);

            $em = Orm::create($DB);
            try {
                $stored = $em->find(QueuedNotification::class, $id);
                $this->object($stored->sent_time)->isInstanceOf(DateTime::class);
                $this->integer($stored->sent_try)->isIdenticalTo(4);
            } finally {
                $em->clear();
            }
            $clock = new DateTimeImmutable('2001-01-01 00:00:00.123456+03:00');
            $transition = new QueuedNotification();
            $transition->mode = 'ajax';
            $transition->recipient = (string)$recipient;
            $this->boolean($transition->acknowledgeBrowserMessage($recipient, $clock))->isTrue();
            $first = $transition->sent_time;
            $this->object($first)->isInstanceOf(DateTime::class);
            $this->string($first->format('Y-m-d H:i:s.uP'))->isIdenticalTo($clock->format('Y-m-d H:i:s.uP'));
            $this->boolean($transition->acknowledgeBrowserMessage($recipient, $clock->modify('+1 day')))->isFalse();
            $this->variable($transition->sent_time)->isIdenticalTo($first);
        } finally {
            $_SESSION = $savedSession;
            $CFG_GLPI = $savedConfig;
        }
    }

    public function testBrowserMessagesMaterializeBeforeRenderingAndReuseTheirOwnedManager(): void
    {
        global $DB, $CFG_GLPI;
        $savedSession = $_SESSION;
        $savedConfig = $CFG_GLPI;
        $writer = null;
        try {
            $this->login();
            $recipient = (int)Session::getLoginUserID();
            $CFG_GLPI['notifications_ajax'] = true;
            $inbox = new BrowserNotificationInbox($DB);
            $rows = [];
            foreach (['ajax', 'ajax', 'AJAX', 'ajax', 'ajax'] as $index => $mode) {
                $rows[] = $this->createItem('QueuedNotification', [
                    'mode' => $mode, 'recipient' => (string)($index === 3 ? $recipient + 1 : $recipient),
                    'entities_id' => 0, 'itemtype' => 'NotificationAjax',
                    'name' => 'Browser presentation ' . $index . ' ' . $this->getUniqueString(),
                    'body_text' => 'Browser content ' . $index, 'sent_time' => null,
                    'create_time' => '2010-01-01 00:00:00', 'send_time' => '2037-01-01 00:00:00',
                ]);
            }
            $ids = array_map(static fn ($row): int => (int)$row->getID(), $rows);
            $connection = $DB->getDoctrineConnection();
            $this->boolean($DB->update('glpi_queuednotifications', ['recipient' => (string)$recipient . ' '], ['id' => $ids[4]]))->isTrue();
            $this->boolean($DB->update('glpi_queuednotifications', ['name' => null, 'body_text' => null], ['id' => $ids[1]]))->isTrue();
            $_SESSION['glpiactiveprofile']['queuednotification'] = 0;
            $_SESSION['glpiactiveentities'] = [];
            $select = static fn (array $messages): array => array_values(array_filter($messages,
                static fn (BrowserNotificationMessage $message): bool => in_array($message->id, $ids, true)));
            $snapshot = $select($inbox->pending($recipient));
            $this->array(array_column($snapshot, 'id'))->isIdenticalTo([$ids[0], $ids[1]]);
            foreach ($snapshot as $message) {
                $this->object($message)->isInstanceOf(BrowserNotificationMessage::class);
            }
            $this->variable($snapshot[1]->title)->isNull();
            $this->variable($snapshot[1]->body)->isNull();
            $this->array($inbox->pending(0))->isEmpty();
            $writer = Orm::create($DB);
            $managed = $writer->find(QueuedNotification::class, $ids[0]);
            $this->integer($connection->update('glpi_queuednotifications', ['name' => 'Current browser title', 'body_text' => 'Current browser body',
                'itemtype' => BrowserNotificationPresentationItem::class],
                ['id' => $ids[0]]))->isIdenticalTo(1);
            $canonical = null;
            Orm::withConnection($connection, static function (EntityManager $manager) use (&$canonical): void {
                $canonical = $manager;
            });
            BrowserNotificationPresentationItem::$formUrl = function () use ($connection, $canonical): void {
                Orm::withConnection($connection, function (EntityManager $manager) use ($canonical): void {
                    $this->object($manager)->isIdenticalTo($canonical);
                    $this->integer($manager->getUnitOfWork()->size())->isIdenticalTo(0);
                });
            };
            $beforeCreations = (new ReflectionProperty(Orm::class, 'unitsOfWork'))->getValue();
            for ($repeat = 0; $repeat < 2; ++$repeat) {
                $current = $select($inbox->pending($recipient));
                $this->string($current[0]->title)->isIdenticalTo('Current browser title');
                $this->string($current[0]->body)->isIdenticalTo('Current browser body');
                $rendered = array_column(LegacyNotificationAjax::getMyNotifications(), null, 'id');
                $this->array($rendered[$ids[0]])->isIdenticalTo([
                    'id' => $ids[0], 'title' => 'Current browser title', 'body' => 'Current browser body', 'url' => '/browser-fixture?id=0',
                ]);
            }
            $this->string($snapshot[0]->title)->isIdenticalTo($rows[0]->fields['name']);
            $this->boolean($writer->contains($managed))->isTrue();
            $this->string($managed->name)->isIdenticalTo($rows[0]->fields['name']);
            $this->boolean($inbox->acknowledge($ids[0], $recipient))->isTrue();
            $this->boolean($inbox->acknowledge($ids[0], $recipient))->isFalse();
            $this->array(array_column($select($inbox->pending($recipient)), 'id'))->isIdenticalTo([$ids[1]]);
            $this->integer((new ReflectionProperty(Orm::class, 'unitsOfWork'))->getValue() - $beforeCreations)->isIdenticalTo(0,
                'Completed browser presentation and acknowledgement scopes reuse the selected private manager');
            $this->boolean($writer->contains($managed))->isTrue();
            $this->boolean($managed->is_deleted)->isFalse();
            // An enclosing operation owns its managed reference while nested acknowledgement writes independently.
            Orm::withConnection($connection, function (EntityManager $parent) use ($inbox, $ids, $recipient): void {
                $reference = $parent->getReference(QueuedNotification::class, $ids[1]);
                $this->boolean($parent->contains($reference))->isTrue();
                $this->boolean($inbox->acknowledge($ids[1], $recipient))->isTrue();
                $this->boolean($parent->contains($reference))->isTrue();
            });
            $this->array($select($inbox->pending($recipient)))->isEmpty();
        } finally {
            $writer?->clear();
            BrowserNotificationPresentationItem::$formUrl = null;
            $_SESSION = $savedSession;
            $CFG_GLPI = $savedConfig;
        }
    }

    public function testCheck()
    {
        $instance = new \NotificationAjax();
        $uid = getItemByTypeName('User', TU_USER, true);
        $this->boolean($instance->check($uid))->isTrue();
        $this->boolean($instance->check(0))->isFalse();
        $this->boolean($instance->check('abc'))->isFalse;
    }

    public function testSendNotification()
    {
        $this->boolean(\NotificationAjax::testNotification())->isTrue();
    }

    public function testGetMyNotifications()
    {
        global $CFG_GLPI;

        //setup
        $this->login();

        $this->boolean(\NotificationAjax::testNotification())->isTrue();
        //another one
        $this->boolean(\NotificationAjax::testNotification())->isTrue();

        //also add a mailing notification to make sure we get only ajax ons back #2997
        $instance = new \NotificationMailing();
        $res = $instance->sendNotification([
           '_itemtype'                   => 'NotificationMailing',
           '_items_id'                   => 1,
           '_notificationtemplates_id'   => 0,
           '_entities_id'                => 0,
           'fromname'                    => 'TEST',
           'subject'                     => 'Test notification',
           'content_text'                => "Hello, this is a test notification.",
           'to'                          => \Session::getLoginUserID(),
           'from'                        => 'glpi@tests',
           'toname'                      => ''
        ]);
        $this->boolean($res)->isTrue();

        //ajax notifications disabled: gets nothing.
        $notifs = \NotificationAjax::getMyNotifications();
        $this->boolean($notifs)->isFalse();

        $CFG_GLPI['notifications_ajax'] = 1;

        $notifs = \NotificationAjax::getMyNotifications();
        $this->array($notifs)->hasSize(2);

        foreach ($notifs as $notif) {
            unset($notif['id']);
            $this->array($notif)->isIdenticalTo([
               'title'  => 'Test notification',
               'body'   => 'Hello, this is a test notification.',
               'url'    => null
            ]);
        }

        //while not deleted, still 2 notifs available
        $notifs = \NotificationAjax::getMyNotifications();
        $this->array($notifs)->hasSize(2);

        //void method
        \NotificationAjax::raisedNotification($notifs[1]['id']);

        $expected = $notifs[0];
        $notifs = \NotificationAjax::getMyNotifications();
        $this->array($notifs)
           ->hasSize(1);
        $this->array($notifs[0])->isIdenticalTo($expected);

        //void method
        \NotificationAjax::raisedNotification($notifs[0]['id']);
        $notifs = \NotificationAjax::getMyNotifications();
        $this->boolean($notifs)->isFalse();

        $computer = getItemByTypeName('Computer', '_test_pc01');
        $instance = new \NotificationAjax();
        $res = $instance->sendNotification([
           '_itemtype'                   => 'Computer',
           '_items_id'                   => $computer->getId(),
           '_notificationtemplates_id'   => 0,
           '_entities_id'                => 0,
           'fromname'                    => 'TEST',
           'subject'                     => 'Test notification',
           'content_text'                => "Hello, this is a test notification.",
           'to'                          => \Session::getLoginUserID()
        ]);
        $this->boolean($res)->isTrue();

        $notifs = \NotificationAjax::getMyNotifications();
        $this->array($notifs)->hasSize(1);
        $this->string($notifs[0]['url'])
           ->isIdenticalTo($computer->getFormURLWithID($computer->fields['id'], false));

        //reset
        $CFG_GLPI['notifications_ajax'] = 0;
    }
}

/** Real item URL dispatch runs after the inbox has materialized its authorized messages. */
class BrowserNotificationPresentationItem extends LegacyCommonDBTM
{
    public static mixed $formUrl = null;

    public static function getFormURL($full = true)
    {
        if (self::$formUrl !== null) {
            (self::$formUrl)();
        }
        return '/browser-fixture';
    }
}
