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

/* Test for inc/planning.class.php */

class Planning extends \DbTestCase
{
    public function testGroupChoiceFormsKeepExactScopeAndFreshLabelsWithoutHydration(): void
    {
        global $DB;
        $session = $_SESSION;
        $manager = null;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $parent = (int)$_SESSION['glpiactive_entity'];
            $scope = $this->createItem(\Entity::class, ['name' => 'Planning choices ' . $this->getUniqueString(), 'entities_id' => $parent]);
            $child = $this->createItem(\Entity::class, ['name' => 'Nested choices', 'entities_id' => (int)$scope->getID()]);
            $groups = [];
            foreach (['Beta', 'Alpha first', 'Alpha second'] as $name) {
                $groups[] = $this->createItem(\Group::class, ['name' => $name, 'entities_id' => (int)$scope->getID()]);
            }
            $inherited = $this->createItem(\Group::class, ['name' => 'Recursive parent', 'entities_id' => $parent, 'is_recursive' => 1]);
            $nested = $this->createItem(\Group::class, ['name' => 'Nested group', 'entities_id' => (int)$child->getID()]);
            foreach ([$groups[1], $groups[2]] as $group) {
                $this->boolean($DB->update('glpi_groups', ['name' => 'Alpha'], ['id' => $group->getID()]))->isTrue();
            }
            $this->setEntity((int)$scope->getID(), true);
            $expected = [
                ['id' => (int)$groups[1]->getID(), 'name' => 'Alpha'],
                ['id' => (int)$groups[2]->getID(), 'name' => 'Alpha'],
                ['id' => (int)$groups[0]->getID(), 'name' => 'Beta'],
            ];
            $manager = \itsmng\Database\Orm::create($DB);
            $repository = new \itsmng\Database\Repository\PlanningRepository($manager);
            $loads = new class {
                public int $count = 0;
                public function postLoad(): void { ++$this->count; }
            };
            $manager->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $loads);
            $this->array($repository->groupChoices((int)$scope->getID()))->isIdenticalTo($expected);
            $this->array($repository->groupChoices((int)$scope->getID(), []))->isEmpty();
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            $manager->find(\itsmng\Database\Entity\Group::class, (int)$groups[0]->getID());
            $this->integer($loads->count)->isGreaterThan(0);

            $options = static function (callable $render): array {
                ob_start();
                try {
                    $render();
                    $html = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                preg_match_all("/<option value='([0-9]+)'>(.*?)<\\/option>/s", $html, $matches, PREG_SET_ORDER);
                return array_map(static fn (array $match): array => ['id' => (int)$match[1], 'name' => $match[2]], $matches);
            };
            $empty = ['id' => 0, 'name' => '-----'];
            $_SESSION['glpiactiveprofile']['planning'] = \Planning::READALL;
            $_SESSION['glpigroups'] = [];
            $this->array($options([\Planning::class, 'showAddGroupForm']))->isIdenticalTo([$empty, ...$expected]);
            $this->array($options([\Planning::class, 'showAddGroupUsersForm']))->isIdenticalTo([$empty, ...$expected]);
            $_SESSION['glpiactiveprofile']['planning'] = \Planning::READGROUP;
            $_SESSION['glpigroups'] = [$groups[0]->getID(), $inherited->getID(), $nested->getID()];
            $this->array($options([\Planning::class, 'showAddGroupForm']))->isIdenticalTo([$empty, $expected[2]]);
            $this->array($options([\Planning::class, 'showAddGroupUsersForm']))->isIdenticalTo([$empty, ...$expected]);
            $_SESSION['glpigroups'] = [];
            $this->array($options([\Planning::class, 'showAddGroupForm']))->isIdenticalTo([$empty]);

            // Reusing the repository and rendering again must observe writes,
            // even with the same Group already managed by the caller's manager.
            $_SESSION['glpigroups'] = [$groups[0]->getID()];
            foreach (['0', '', null, 'After write'] as $name) {
                $this->boolean($DB->update('glpi_groups', ['name' => $name], ['id' => $groups[0]->getID()]))->isTrue();
                $this->array($repository->groupChoices((int)$scope->getID(), $_SESSION['glpigroups']))
                    ->isIdenticalTo([['id' => (int)$groups[0]->getID(), 'name' => $name]]);
                $this->array($options([\Planning::class, 'showAddGroupForm']))
                    ->isIdenticalTo([$empty, ['id' => (int)$groups[0]->getID(), 'name' => (string)$name]]);
            }
        } finally {
            $_SESSION = $session;
            $manager?->clear();
        }
    }

    public function testFilterExportsUseFreshSessionUserTokensAndLegacyIssuance(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $session = $_SESSION;
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $bufferLevel = ob_get_level();
        try {
            $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $prefix = 'Planning token ' . bin2hex(random_bytes(6));
            $actor = $this->createItem(\User::class, [
                'name' => $prefix . ' actor', 'entities_id' => $entity, 'authtype' => \Auth::DB_GLPI,
            ]);
            $owner = $this->createItem(\User::class, [
                'name' => $prefix . ' owner', 'entities_id' => $entity, 'authtype' => \Auth::DB_GLPI,
            ]);
            $id = (int)$owner->getID();
            $_SESSION['glpiID'] = $id;
            // Issuing one's own personal token does not require user-management rights.
            $_SESSION['glpiactiveprofile']['user'] = 0;
            $this->boolean((bool)\Session::haveRight('user', UPDATE))->isFalse();
            $repository = new \itsmng\Database\Repository\UserRepository(\itsmng\Database\Orm::create($DB));
            $actorToken = str_repeat('a', 40);
            $connection->update('glpi_users', ['personal_token' => $actorToken], ['id' => $actor->getID()]);
            $render = static function () use ($actor): string {
                ob_start();
                \Planning::showSingleLinePlanningFilter('User_' . $actor->getID(), ['type' => 'user', 'display' => true]);
                return ob_get_clean();
            };
            $tokens = static function (string $html): array {
                preg_match_all('/[&]token=([^\x27]*)\x27/', $html, $matches);
                return $matches[1];
            };
            foreach ([str_repeat('b', 40), str_repeat('c', 40)] as $token) {
                $connection->update('glpi_users', ['personal_token' => $token], ['id' => $id]);
                $html = $render();
                $this->array($tokens($html))->isIdenticalTo([$token, $token]);
                $this->string($html)->contains('&uID=' . $actor->getID() . '&gID=0');
                $this->string($html)->contains($CFG_GLPI['url_base'] . '/caldav.php/'
                    . \Glpi\CalDAV\Backend\Calendar::PREFIX_USERS . '/' . $actor->fields['name'] . '/'
                    . \Glpi\CalDAV\Backend\Calendar::BASE_CALENDAR_URI);
                $this->string($repository->tokenValue($id, 'personal_token'))->isIdenticalTo($token);
            }
            foreach ([null, '', '0'] as $emptyToken) {
                $connection->update('glpi_users', ['personal_token' => $emptyToken, 'personal_token_date' => null], ['id' => $id]);
                $html = $render();
                $stored = $repository->tokenValue($id, 'personal_token');
                $this->string($stored)->hasLength(40);
                $this->array($tokens($html))->isIdenticalTo([$stored, $stored]);
                $this->boolean($owner->getFromDB($id))->isTrue();
                $this->string($owner->fields['personal_token_date'])->isIdenticalTo($_SESSION['glpi_currenttime']);
                $this->array($tokens($render()))->isIdenticalTo([$stored, $stored]);
            }
            $_SESSION['glpiID'] = PHP_INT_MAX;
            $this->array($tokens($render()))->isIdenticalTo(['', '']);
            $this->variable($repository->tokenValue(PHP_INT_MAX, 'personal_token'))->isNull();
            $this->string($repository->tokenValue((int)$actor->getID(), 'personal_token'))->isIdenticalTo($actorToken);
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            $_SESSION = $session;
        }
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
    }

    public function testTimelineNamesKeepResourceOrderAndDynamicWriteBoundaries(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $session = $_SESSION;
        $idsVisible = $CFG_GLPI['is_ids_visible'];
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $em = new class($connection, \itsmng\Database\Orm::configuration($connection->getDatabasePlatform())) extends \Doctrine\ORM\EntityManager {
            public int $queries = 0;
            public function createQuery(string $dql = ''): \Doctrine\ORM\Query
            {
                ++$this->queries;
                return parent::createQuery($dql);
            }
        };
        $listener = new class {
            public int $loaded = 0;
            public function postLoad(): void
            {
                ++$this->loaded;
            }
        };
        $em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $listener);
        try {
            $prefix = 'Planning names ' . bin2hex(random_bytes(6));
            $user = $this->createItem(\User::class, [
                'name' => $prefix, 'firstname' => 'Ada', 'realname' => 'Reader',
                'entities_id' => $entity, 'authtype' => \Auth::DB_GLPI,
            ]);
            $first = $this->createItem(\Group::class, ['name' => $prefix . ' first', 'entities_id' => $entity]);
            $second = $this->createItem(\Group::class, ['name' => $prefix . ' second', 'entities_id' => $entity]);
            $id = (int)$user->getID();
            $missing = PHP_INT_MAX;
            $repository = new \itsmng\Database\Repository\UserRepository($em);
            $this->array($repository->friendlyNameData([]))->isEmpty();
            $this->integer($em->queries)->isIdenticalTo(0);
            $names = $repository->friendlyNameData([$id, $id, $missing]);
            $this->array($names)->hasSize(1);
            $this->array(array_keys($names[$id]))->isIdenticalTo(['id', 'name', 'realname', 'firstname']);
            $this->integer($em->queries)->isIdenticalTo(1);
            $this->integer($listener->loaded)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
            $managed = $em->find(\itsmng\Database\Entity\User::class, $id);
            $this->integer($listener->loaded)->isIdenticalTo(1);
            $connection->update('glpi_users', ['firstname' => 'Grace'], ['id' => $id]);
            $this->string($repository->friendlyNameData([$id])[$id]['firstname'])->isIdenticalTo('Grace');
            $this->string($managed->firstname)->isIdenticalTo('Ada');
            $this->boolean($em->contains($managed))->isTrue();
            $this->integer($listener->loaded)->isIdenticalTo(1);

            $firstKey = 'Group_' . $first->getID();
            $secondKey = 'Group_' . $second->getID();
            $userKey = 'User_' . $id;
            $missingKey = 'User_' . $missing;
            $writerKey = PlanningTimelineWriter::class . '_1';
            $_SESSION['glpi_plannings']['plannings'] = [
                'external_1' => ['type' => 'external', 'name' => 'External title', 'display' => false],
                $firstKey => ['type' => 'group_users', 'display' => true, 'users' => [$userKey => [], $missingKey => []]],
                $writerKey => ['type' => 'custom', 'display' => true],
                $secondKey => ['type' => 'group_users', 'display' => false, 'users' => [$userKey => []]],
                $userKey => ['type' => 'user', 'display' => true],
            ];
            PlanningTimelineWriter::$write = static function () use ($connection, $id): void {
                $connection->update('glpi_users', ['firstname' => 'Grace'], ['id' => $id]);
            };
            foreach ([\User::FIRSTNAME_BEFORE, \User::REALNAME_BEFORE] as $format) {
                $_SESSION['glpinames_format'] = $format;
                $_SESSION['glpiis_ids_visible'] = $CFG_GLPI['is_ids_visible'] = 1;
                $connection->update('glpi_users', ['firstname' => 'Ada'], ['id' => $id]);
                $this->boolean($user->getFromDB($id))->isTrue();
                $before = $user->getName();
                $resources = \Planning::getTimelineResources();
                $this->boolean($user->getFromDB($id))->isTrue();
                $after = $user->getName();
                $this->string($before)->isNotIdenticalTo($after);
                $this->array(array_column($resources, 'id'))->isIdenticalTo([
                    'external_1', $firstKey, 'gu_' . $userKey, 'gu_' . $missingKey,
                    $writerKey, $secondKey, 'gu_' . $userKey, $userKey,
                ]);
                $this->array(array_column($resources, 'title'))->isIdenticalTo([
                    'External title', $first->getName(), $before, NOT_AVAILABLE,
                    'Dynamic writer', $second->getName(), $after, $after,
                ]);
                $this->array(array_column($resources, 'is_visible'))->isIdenticalTo([false, true, true, true, true, false, false, true]);
                $this->string($resources[2]['parentId'])->isIdenticalTo($firstKey);
                $this->string($resources[6]['parentId'])->isIdenticalTo($secondKey);
                $this->integer($_SESSION['glpiis_ids_visible'])->isIdenticalTo(1);
                $this->integer($CFG_GLPI['is_ids_visible'])->isIdenticalTo(1);
            }
        } finally {
            PlanningTimelineWriter::$write = null;
            $em->getEventManager()->removeEventListener([\Doctrine\ORM\Events::postLoad], $listener);
            $em->clear();
            $_SESSION = $session;
            $CFG_GLPI['is_ids_visible'] = $idsVisible;
        }
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
    }

    public function testCloneEvent()
    {
        $this->login();

        $input = [
           'name'  => "test event to clone",
           'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
           'plan'  => [
              'begin'     => date('Y-m-d H:i:s'),
              '_duration' => 2 * HOUR_TIMESTAMP
           ],
           'rrule' => '{"freq":"weekly","interval":"1"}'
        ];

        $event = new \PlanningExternalEvent();
        $this->integer($event_id = $event->add($input))->isGreaterThan(0);

        $timestamp = time() + DAY_TIMESTAMP;
        $new_start = date('Y-m-d H:i:s', $timestamp);
        $new_end   = date('Y-m-d H:i:s', $timestamp + 2 * HOUR_TIMESTAMP);

        $this->integer($clone_events_id = \Planning::cloneEvent([
           'old_itemtype' => 'PlanningExternalEvent',
           'old_items_id' => $event_id,
           'start'        => $new_start,
           'end'          => $new_end
        ]))->isGreaterThan(0);

        // check cloned event
        $this->boolean($event->getFromDB($clone_events_id))->isTrue();
        $this->array($event->fields)
           ->string['begin']->isEqualTo($new_start)
           ->string['end']->isEqualTo($new_end)
           ->string['rrule']->isEqualTo($input['rrule']);
        $this->string($event->fields['name'])->contains(sprintf(__('Copy of %s'), $input['name']));
    }

    public function testGetExternalCalendarEvents()
    {

        $this->login();

        $session_backup = $_SESSION['glpi_plannings'];

        \Planning::initSessionForCurrentUser();

        // Expected results
        $expected_events_data = [
           'recurring_evt_1' => [
              'title'   => 'Recur event',
              'tooltip' => "Recur event\nMonday, 11 am to 12 am starting on 1st of July",
              'color'   => '#ff0000',
              'rrule'   => "DTSTART:20190701T090000\nRRULE:FREQ=WEEKLY;BYDAY=MO\n",
           ],
           'simple_evt_1'    => [
              'title'   => 'Base event with no desc',
              'tooltip' => 'Base event with no desc',
              'color'   => '#ff0000',
           ],
           'simple_evt_2'    => [
              'title'   => 'An event in 2020/01/05',
              'tooltip' => "An event in 2020/01/05\nDescription of my event",
              'color'   => '#ff0000',
           ],
           'task_1'          => [
              'title'   => 'Test task',
              'tooltip' => "Test task\nDescription of the task.",
              'color'   => '#ff0000',
           ],
           'another_evt_1'   => [
              'title'   => 'Another event',
              'tooltip' => "Another event\nAnother event description",
              'color'   => '#a500b3',
           ],
        ];

        $expected_events_keys = [
           'all_events'   => ['recurring_evt_1', 'simple_evt_1', 'simple_evt_2', 'task_1', 'another_evt_1'],
           'month_events' => ['recurring_evt_1', 'simple_evt_1', 'task_1', 'another_evt_1'],
           'cal2_events'  => ['another_evt_1'],
        ];

        // Add calendars
        $_SESSION['glpi_plannings']['plannings'] = [
           'external_1' => [
              'color'   => '#ff0000',
              'display' => true,
              'type'    => 'external',
              'name'    => 'External calendar 1',
              'url'     => 'file://' . realpath(GLPI_ROOT . '/tests/fixtures/ical/sample_1.ics'),
           ],
           'external_2' => [
              'color'   => '#a500b3',
              'display' => true,
              'type'    => 'external',
              'name'    => 'External calendar 2',
              'url'     => 'file://' . realpath(GLPI_ROOT . '/tests/fixtures/ical/sample_2.ics'),
           ],
        ];

        // Fetch all events
        $all_events = \Planning::constructEventsArray(
            [
              'start'            => '2000-01-01 00:00:00',
              'end'              => '2050-12-31 23:59:59',
              'force_all_events' => true
         ]
        );
        // Fetch events only for a given month
        $month_events = \Planning::constructEventsArray(
            [
              'start' => '2019-11-01 00:00:00',
              'end'   => '2019-11-30 23:59:59',
         ]
        );
        // Fetch events only for a given calendar
        $_SESSION['glpi_plannings']['plannings']['external_1']['display'] = false;
        $cal2_events = \Planning::constructEventsArray(
            [
              'start' => '2019-11-01 00:00:00',
              'end'   => '2019-11-30 23:59:59',
         ]
        );

        $_SESSION['glpi_plannings'] = $session_backup;

        foreach ($expected_events_keys as $list_var => $events_keys) {
            $events_list = $$list_var;
            $this->array($events_list)->hasSize(count($events_keys));
            foreach ($events_keys as $index => $evt_key) {
                $event_data = $expected_events_data[$evt_key];
                $this->array($events_list)->hasKey($index);
                $this->array($events_list[$index])
                   ->string['title']->isEqualTo($event_data['title'])
                   ->string['tooltip']->isEqualTo($event_data['tooltip'])
                   ->string['color']->isEqualTo($event_data['color']);
                if (array_key_exists('rrule', $event_data)) {
                    $this->array($events_list[$index])
                       ->string['rrule']
                          ->isEqualTo($event_data['rrule']);
                } else {
                    $this->array($events_list[$index])->notHasKey('rrule');
                }
            }
        }
    }
}


/** A dynamic planning resource: its read is an observable write boundary. */
class PlanningTimelineWriter extends \CommonDBTM
{
    public static ?\Closure $write = null;

    public function getFromDB($ID)
    {
        (self::$write)();
        return true;
    }

    public function getName($options = [])
    {
        return 'Dynamic writer';
    }
}
