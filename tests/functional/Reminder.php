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
use Doctrine\Common\EventManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Entity_Reminder;
use Group;
use Group_Reminder;
use Profile_Reminder;
use ReflectionProperty;
use Reminder as ReminderModel;
use Reminder_User;
use itsmng\Database\Entity;
use mock\DBmysql as ReminderAdapterProbe;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/reminder.class.php */

class Reminder extends DbTestCase
{
    public function testAudienceReloadKeepsRowsFreshAndCustomLifecycle(): void
    {
        global $DB;
        $originalAdapter = $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', false);
            $entity = (int)$_SESSION['glpiactive_entity'];
            $user = (int)$_SESSION['glpiID'];
            $profile = (int)$_SESSION['glpiactiveprofile']['id'];
            $group = $this->createItem(Group::class, ['name' => 'Reminder audience ' . $this->getUniqueString(), 'entities_id' => $entity]);
            $reminder = $this->createItem(ReminderModel::class, ['name' => 'Audience owner ' . $this->getUniqueString(),
                'users_id' => $user, 'text' => 'Current reminder audiences']);
            $other = $this->createItem(ReminderModel::class, ['name' => 'Other audience ' . $this->getUniqueString(),
                'users_id' => $user, 'text' => 'Keep parent scoping']);
            $audiences = [
                ['users', Reminder_User::class, 'users_id', $user, []],
                ['entities', Entity_Reminder::class, 'entities_id', $entity, ['is_recursive' => 0]],
                ['groups', Group_Reminder::class, 'groups_id', (int)$group->getID(), ['entities_id' => $entity, 'is_recursive' => 0]],
                ['profiles', Profile_Reminder::class, 'profiles_id', $profile, ['entities_id' => $entity, 'is_recursive' => 0]],
            ];
            $links = [];
            foreach ($audiences as [$property, $class, $column, $id, $extra]) {
                $values = [$column => $id] + $extra;
                $links[$property] = $this->createItem($class, ['reminders_id' => $reminder->getID()] + $values);
                $this->createItem($class, ['reminders_id' => $other->getID()] + $values);
            }
            $connection = $DB->getDoctrineConnection();
            $level = $connection->getTransactionNestingLevel();
            $loaded = new ReminderModel();
            $this->boolean($loaded->getFromDB($reminder->getID()))->isTrue();
            foreach ($audiences as [$property, $class, $column, $id]) {
                $rows = (new ReflectionProperty(ReminderModel::class, $property))->getValue($loaded);
                $this->array(array_keys($rows))->isIdenticalTo([$id]);
                $this->array(array_map('intval', array_column($rows[$id], 'id')))
                    ->isIdenticalTo([(int)$links[$property]->getID()]);
            }
            $this->integer($connection->update('glpi_groups_reminders', ['is_recursive' => true], ['id' => $links['groups']->getID()]))
                ->isIdenticalTo(1);
            $this->boolean($loaded->getFromDB($reminder->getID()))->isTrue();
            $groups = (new ReflectionProperty(ReminderModel::class, 'groups'))->getValue($loaded);
            $this->integer((int)$groups[$group->getID()][0]['is_recursive'])->isIdenticalTo(1);

            $events = new EventManager();
            $observer = new class () {
                public array $loaded = [];
                public int $clears = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    $record = $event->getObject();
                    $this->loaded[] = $record::class;
                    if ($record instanceof Entity\GroupReminder) {
                        $record->is_recursive = false;
                    }
                }
                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $events->addEventListener(['postLoad', 'onClear'], $observer);
            $probe = new class ($connection) extends ScalarReadProbe {
                public EventManager $events;
                public function getEventManager(): EventManager
                {
                    return $this->events;
                }
            };
            $probe->events = $events;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new ReminderAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = $probe;
            $this->calling($adapter)->getProvider = $originalAdapter->getProvider();
            $DB = $adapter;
            $this->boolean($loaded->getFromDB($reminder->getID()))->isTrue();
            foreach ([Entity\ReminderUser::class, Entity\EntityReminder::class,
                Entity\GroupReminder::class, Entity\ProfileReminder::class] as $class) {
                $this->array($observer->loaded)->contains($class);
            }
            $groups = (new ReflectionProperty(ReminderModel::class, 'groups'))->getValue($loaded);
            $this->integer((int)$groups[$group->getID()][0]['is_recursive'])->isIdenticalTo(0);
            $clears = $observer->clears;
            $rows = Group_Reminder::getGroups($reminder->getID());
            $this->integer((int)$rows[$group->getID()][0]['is_recursive'])->isIdenticalTo(0);
            $this->integer($observer->clears)->isIdenticalTo($clears);
            $DB = $originalAdapter;
            foreach ($audiences as [$property, $class]) {
                $this->integer($connection->delete($class::getTable(), ['reminders_id' => $reminder->getID()]))->isIdenticalTo(1);
            }
            $this->boolean($loaded->getFromDB($reminder->getID()))->isTrue();
            foreach (['users', 'entities', 'groups', 'profiles'] as $property) {
                $this->array((new ReflectionProperty(ReminderModel::class, $property))->getValue($loaded))->isEmpty();
            }
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $DB = $originalAdapter;
            $_SESSION = $session;
        }
    }

    public function testAddVisibilityRestrict()
    {
        global $DB;
        $userColumn = $DB::quoteName('glpi_reminders.users_id');
        $profileColumn = $DB::quoteName('glpi_profiles_reminders.profiles_id');
        $groupColumn = $DB::quoteName('glpi_groups_reminders.groups_id');

        //first, as a super-admin
        $this->login();
        $restrict = trim((string) preg_replace('/\s+/', ' ', \Reminder::addVisibilityRestrict()));
        $this->string($restrict)
           ->contains("$userColumn = '" . $_SESSION['glpiID'] . "'")
           ->contains("$profileColumn = '" . $_SESSION['glpiactiveprofile']['id'] . "'");

        $this->login('normal', 'normal');
        $restrict = trim((string) preg_replace('/\s+/', ' ', \Reminder::addVisibilityRestrict()));
        $this->string($restrict)
           ->contains("$userColumn = '" . $_SESSION['glpiID'] . "'")
           ->contains("$profileColumn = '" . $_SESSION['glpiactiveprofile']['id'] . "'");

        $this->login('tech', 'tech');
        $restrict = trim((string) preg_replace('/\s+/', ' ', \Reminder::addVisibilityRestrict()));
        $this->string($restrict)
           ->contains("$userColumn = '" . $_SESSION['glpiID'] . "'")
           ->contains("$profileColumn = '" . $_SESSION['glpiactiveprofile']['id'] . "'");

        $bkp_groups = $_SESSION['glpigroups'];
        $_SESSION['glpigroups'] = [42, 1337];
        $str = \Reminder::addVisibilityRestrict();
        $_SESSION['glpigroups'] = $bkp_groups;
        $this->string(trim((string) preg_replace('/\s+/', ' ', $str)))
           ->contains("$groupColumn IN ('42', '1337')");
    }
}
