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
use DbTestCase;
use Doctrine\Common\EventManager;
use CommonDBTM;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Query;
use Group;
use Group_User as LegacyGroup_User;
use Plugin;
use Profile_User;
use ReflectionProperty;
use mock\DBmysql as GroupRouteAdapter;
use tests\fixtures\ScalarReadProbe;
use User;
use itsmng\Database\Entity\User as UserEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\GroupMembershipRepository;
use itsmng\Database\UnsupportedCriteria;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/group_user.class.php */

class Group_User extends DbTestCase
{
    public function testGroupItemsSelectCallbackRoutesAndClearEachOwnerAtTheEnd(): void
    {
        global $DB, $CFG_GLPI;
        $original = $DB;
        $session = $_SESSION;
        $tables = $CFG_GLPI['glpitablesitemtype'];
        $this->login();
        $this->setEntity('_test_root_entity', true);
        try {
            $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $group = $this->createItem(Group::class, ['name' => 'Route group ' . $this->getUniqueString(), 'entities_id' => $entity]);
            $computer = $this->createItem(Computer::class, [
                'name' => 'Route computer ' . $this->getUniqueString(), 'entities_id' => $entity, 'groups_id' => $group->getID(),
            ]);
            foreach ([GroupRouteComputer::class, GroupRouteComputerSecond::class] as $type) {
                $CFG_GLPI['glpitablesitemtype'][$type] = 'glpi_computers';
            }
            $connection = $DB->getDoctrineConnection();
            $probes = $adapters = $listeners = [];
            $this->mockGenerator()->orphanize('__construct');
            for ($route = 0; $route < 3; ++$route) {
                $probe = new class ($connection) extends ScalarReadProbe {
                    public EventManager $events;
                    public function getEventManager(): EventManager
                    {
                        return $this->events;
                    }
                };
                $probe->events = new EventManager();
                $listener = new class () {
                    public int $clears = 0;
                    public function onClear(): void
                    {
                        ++$this->clears;
                    }
                };
                $probe->events->addEventListener(['onClear'], $listener);
                $adapter = new GroupRouteAdapter();
                $this->calling($adapter)->getDoctrineConnection = $probe;
                $this->calling($adapter)->getProvider = $original->getProvider();
                $probes[] = $probe;
                $adapters[] = $adapter;
                $listeners[] = $listener;
            }
            $_SESSION['glpilist_limit'] = 10;
            GroupRouteComputer::$onView = static function (string $type) use ($adapters): bool {
                $GLOBALS['DB'] = $adapters[$type === GroupRouteComputer::class ? 1 : 2];
                return true;
            };
            $rows = [];
            $DB = $adapters[0];
            $this->integer($group->getDataItems([GroupRouteComputer::class], 'groups_id', false, false, 0, $rows))->isIdenticalTo(1);
            $this->array($rows)->isIdenticalTo([['itemtype' => GroupRouteComputer::class, 'items_id' => (int)$computer->getID()]]);
            $this->array($probes[0]->queries)->isEmpty('Group item queries follow the callback-selected route');
            $this->array($probes[1]->queries)->hasSize(2);
            $this->integer($listeners[0]->clears)->isIdenticalTo(0);
            $this->integer($listeners[1]->clears)->isIdenticalTo(1);

            foreach ($probes as $probe) {
                $probe->queries = [];
            }
            foreach ($listeners as $listener) {
                $listener->clears = 0;
            }
            $atSecondCallback = null;
            GroupRouteComputer::$onView = static function (string $type) use ($adapters, $listeners, &$atSecondCallback): bool {
                if ($type === GroupRouteComputerSecond::class) {
                    $atSecondCallback = $listeners[1]->clears;
                }
                $GLOBALS['DB'] = $adapters[$type === GroupRouteComputer::class ? 1 : 2];
                return true;
            };
            $DB = $adapters[0];
            $types = [GroupRouteComputer::class, GroupRouteComputerSecond::class];
            $this->integer($group->getDataItems($types, 'groups_id', false, false, 0, $rows))->isIdenticalTo(2);
            $this->array($rows)->isIdenticalTo(array_map(static fn (string $type): array =>
                ['itemtype' => $type, 'items_id' => (int)$computer->getID()], $types));
            // Counts follow each callback; both ID reads follow the last selected route, as before the ORM conversion.
            $this->array($probes[0]->queries)->isEmpty();
            $this->array($probes[1]->queries)->hasSize(1);
            $this->array($probes[2]->queries)->hasSize(3);
            $this->integer($atSecondCallback)->isIdenticalTo(0);
            $this->integer($listeners[0]->clears)->isIdenticalTo(0);
            $this->integer($listeners[1]->clears)->isIdenticalTo(1);
            $this->integer($listeners[2]->clears)->isIdenticalTo(1);
        } finally {
            GroupRouteComputer::$onView = null;
            $DB = $original;
            $_SESSION = $session;
            $CFG_GLPI['glpitablesitemtype'] = $tables;
        }
    }

    public function testPaginatedMemberLinksKeepTreeScopeAndCurrentLabels(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $PLUGIN_HOOKS['item_can'] = [];
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $prefix = 'Member presentation ' . bin2hex(random_bytes(6));
        try {
            $group = $this->createItem(Group::class, [
                'name' => $prefix . ' parent', 'entities_id' => $entity, 'comment' => 'Parent tooltip',
            ]);
            $child = $this->createItem(Group::class, [
                'name' => $prefix . ' child', 'entities_id' => $entity, 'groups_id' => $group->getID(), 'comment' => 'Child tooltip',
            ]);
            $users = [];
            foreach ([$group, $child] as $index => $owner) {
                $users[$index] = $this->createItem(User::class, [
                    'name' => $prefix . ' user ' . $index, 'entities_id' => $entity, 'authtype' => Auth::DB_GLPI,
                ]);
                $this->createItem(LegacyGroup_User::class, [
                    'groups_id' => $owner->getID(), 'users_id' => $users[$index]->getID(), 'is_manager' => $index,
                ]);
            }
            $connection = $DB->getDoctrineConnection();
            $level = $connection->getTransactionNestingLevel();
            $em = new class ($connection, Orm::configuration($connection->getDatabasePlatform())) extends EntityManager {
                public int $queries = 0;
                public function createQuery(string $dql = ''): Query
                {
                    ++$this->queries;
                    return parent::createQuery($dql);
                }
            };
            $loads = new class () {
                public int $count = 0;
                public function postLoad(): void
                {
                    ++$this->count;
                }
            };
            $em->getEventManager()->addEventListener([Events::postLoad], $loads);
            try {
                $repository = new GroupMembershipRepository($em);
                $ids = [(int)$group->getID(), (int)$child->getID()];
                $legacy = $repository->members($ids, []);
                $this->array(array_keys($legacy['rows'][0]))->isIdenticalTo([
                    'id', 'linkid', 'groups_id', 'is_dynamic', 'is_manager', 'is_userdelegate',
                ]);
                $em->queries = 0;
                $page = $repository->members($ids, [], withLinkFields: true);
                $this->integer($em->queries)->isIdenticalTo(2);
                $this->integer($page['total'])->isIdenticalTo(2);
                $this->array($page['rows'])->hasSize(2);
                $this->integer($loads->count)->isIdenticalTo(0);
                $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
                $managed = $em->find(UserEntity::class, (int)$users[0]->getID());
                $oldFirstname = $managed->firstname;
                $this->boolean($DB->update('glpi_users', ['firstname' => 'Grace'], ['id' => $users[0]->getID()]))->isTrue();
                $fresh = $repository->members([(int)$group->getID()], [], withLinkFields: true);
                $this->string($fresh['rows'][0]['user_firstname'])->isIdenticalTo('Grace');
                $this->variable($managed->firstname)->isIdenticalTo($oldFirstname);
                $this->integer($loads->count)->isIdenticalTo(1);
                $this->boolean($em->contains($managed))->isTrue();
                $this->object($em->getConnection())->isIdenticalTo($connection);
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
                $this->boolean($users[0]->getFromDB($users[0]->getID()))->isTrue();
            } finally {
                $em->getEventManager()->removeEventListener([Events::postLoad], $loads);
                $em->clear();
            }
            // _entities_id is a public add instruction, not a persisted field for
            // DbTestCase::checkInput to compare through getField().
            $outside = new User();
            $this->integer((int)$outside->add([
                'name' => $prefix . ' outside', 'entities_id' => 0, '_entities_id' => 0, 'authtype' => Auth::DB_GLPI,
            ]))->isGreaterThan(0);
            $this->boolean($outside->getFromDB($outside->getID()))->isTrue();
            $this->variable($outside->getField('entities_id'))->isEqualTo(0);
            $this->createItem(Profile_User::class, [
                'users_id' => $outside->getID(), 'profiles_id' => $_SESSION['glpiactiveprofile']['id'],
                'entities_id' => 0, 'is_recursive' => 0,
            ]);
            $this->array(array_values(array_map('intval', Profile_User::getUserEntities($outside->getID()))))->isIdenticalTo([0]);
            $this->createItem(LegacyGroup_User::class, ['groups_id' => $group->getID(), 'users_id' => $outside->getID()]);
            $this->boolean($group->can((int)$group->getID(), READ))->isTrue();
            $this->boolean($users[0]->can((int)$users[0]->getID(), READ))->isTrue();
            $assertGroupLink = function (string $html, Group $item, bool $linked = true): void {
                // Each tooltip owns a random DOM ID; assert its stable presentation
                // and target instead of comparing independently rendered fragments.
                $this->string($html)->contains($item->getNameID())->contains($item->fields['comment']);
                if ($linked) {
                    $this->string($html)->contains("href='" . $item->getLinkURL() . "'")
                        ->contains('title="' . htmlentities($item->getName(['complete' => true]), ENT_QUOTES, 'utf-8') . '"');
                } else {
                    $this->string($html)->notContains('<a ');
                }
            };
            $direct = LegacyGroup_User::getPaginatedMembersForGroup($group);
            $this->integer($direct['total'])->isIdenticalTo(1);
            $this->array($direct['rows'])->hasSize(1);
            $this->string($direct['rows'][0]['group'])->isIdenticalTo($users[0]->getLink());
            $assertGroupLink($direct['rows'][0]['parent'], $group);
            $tree = LegacyGroup_User::getPaginatedMembersForGroup($group, '', 1);
            $this->integer($tree['total'])->isIdenticalTo(2);
            $this->array($tree['rows'])->hasSize(2);
            foreach ([$group, $child] as $index => $owner) {
                $assertGroupLink($tree['rows'][$index]['group'], $owner);
                $assertGroupLink($tree['rows'][$index]['parent'], $owner);
            }
            $this->string($tree['rows'][1]['manager'])->contains(__('Manager'));
            $members = $ids = [];
            LegacyGroup_User::getDataForGroup($group, $members, $ids);
            $this->array(array_map('intval', array_column($members, 'id')))->isIdenticalTo([(int)$users[0]->getID()]);
            $this->array(array_map('intval', $ids))->isIdenticalTo([(int)$users[0]->getID()]);
            $members = $ids = [];
            LegacyGroup_User::getDataForGroup($group, $members, $ids, '', 1);
            $this->array($members)->hasSize(2);
            $this->array(array_map('intval', array_column($members, 'id')))
                ->contains((int)$users[0]->getID())->contains((int)$users[1]->getID());
            $this->array(array_map('intval', $ids))->isIdenticalTo([(int)$users[0]->getID()]);
            $members = $ids = [];
            LegacyGroup_User::getDataForGroup($group, $members, $ids, 'is_manager', 1);
            $this->array(array_map('intval', array_column($members, 'id')))->isIdenticalTo([(int)$users[1]->getID()]);
            $this->array(array_map('intval', $ids))->isIdenticalTo([(int)$users[0]->getID()]);
            $page = LegacyGroup_User::getPaginatedMembersForGroup($group, 'is_manager', 1, 0, 1);
            $this->integer($page['total'])->isIdenticalTo(1);
            $this->array($page['rows'])->hasSize(1);
            $assertGroupLink($page['rows'][0]['group'], $child);

            $this->boolean($DB->update('glpi_groups', ['comment' => 'Current child tooltip'], ['id' => $child->getID()]))->isTrue();
            $this->boolean($child->getFromDB($child->getID()))->isTrue();
            $fresh = LegacyGroup_User::getPaginatedMembersForGroup($group, 'is_manager', 1);
            $assertGroupLink($fresh['rows'][0]['group'], $child);
            $_SESSION['glpiactiveprofile']['group'] = 0;
            $denied = LegacyGroup_User::getPaginatedMembersForGroup($group, 'is_manager', 1);
            $assertGroupLink($denied['rows'][0]['group'], $child, false);
        } finally {
            $_SESSION = $session;
            $PLUGIN_HOOKS = $hooks;
        }
    }

    public function testMemberPermissionHooksKeepCompleteFieldsAndLaterReads(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)$_SESSION['glpiactive_entity'];
            $group = $this->createItem(Group::class, ['name' => $this->getUniqueString(),
                'entities_id' => $entity, 'comment' => 'Before callback', 'ldap_value' => 'Complete group fields']);
            $users = [];
            foreach (['Alpha', 'Zulu'] as $name) {
                $user = $this->createItem(User::class, ['name' => $this->getUniqueString(), 'realname' => $name,
                    'entities_id' => $entity, 'authtype' => Auth::DB_GLPI, 'comment' => 'Complete user fields']);
                $users[] = $user;
                $this->createItem(LegacyGroup_User::class, ['groups_id' => $group->getID(), 'users_id' => $user->getID()]);
            }
            $first = (int)$users[0]->getID();
            $second = (int)$users[1]->getID();
            $connection = $DB->getDoctrineConnection();
            $level = $connection->getTransactionNestingLevel();
            $calls = [];
            $callback = static function (CommonDBTM $model) use (&$calls, $connection, $first, $second, $group): void {
                $calls[] = ['type' => $model->getType(), 'fields' => $model->fields, 'right' => $model->right];
                if ($model instanceof User && (int)$model->getID() === $first) {
                    $connection->update('glpi_users', ['firstname' => 'After callback'], ['id' => $second]);
                    $connection->update('glpi_groups', ['comment' => 'After callback'], ['id' => $group->getID()]);
                    $model->right = false;
                }
            };
            $plugins->setValue(null, [...$active, 'member_link_fixture']);
            $PLUGIN_HOOKS['item_can'] = ['member_link_fixture' => [User::class => $callback, Group::class => $callback]];
            $page = LegacyGroup_User::getPaginatedMembersForGroup($group);
            $this->integer($page['total'])->isIdenticalTo(2);
            $this->array($page['rows'])->hasSize(2);
            $this->array(array_column($calls, 'type'))->isIdenticalTo(['User', 'Group', 'User', 'Group']);
            $this->array(array_column($calls, 'right'))->isIdenticalTo([READ, READ, READ, READ]);
            foreach ([0, 2] as $index) {
                $this->string($calls[$index]['fields']['comment'])->isIdenticalTo('Complete user fields');
            }
            foreach ([1, 3] as $index) {
                $this->string($calls[$index]['fields']['ldap_value'])->isIdenticalTo('Complete group fields');
            }
            $this->string($calls[1]['fields']['comment'])->isIdenticalTo('Before callback');
            $this->string($calls[3]['fields']['comment'])->isIdenticalTo('After callback');
            $this->string($calls[2]['fields']['firstname'])->isIdenticalTo('After callback');
            $this->string($page['rows'][0]['group'])->notContains('<a ')->contains('Alpha');
            $this->string($page['rows'][1]['group'])->contains('<a ')->contains('After callback');
            $this->string($page['rows'][0]['parent'])->contains('Before callback');
            $this->string($page['rows'][1]['parent'])->contains('After callback');
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $_SESSION = $session;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
    }

    public function testMembershipReadsFollowTheCurrentAdapter(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $group = $this->createItem(Group::class, ['name' => $this->getUniqueString(), 'comment' => 'First route']);
            $uid = (int)getItemByTypeName(User::class, 'tech', true);
            $this->createItem(LegacyGroup_User::class, ['groups_id' => $group->getID(), 'users_id' => $uid]);
            $connection = $original->getDoctrineConnection();
            $probes = $adapters = [];
            $this->mockGenerator()->orphanize('__construct');
            for ($route = 0; $route < 2; ++$route) {
                $probe = new ScalarReadProbe($connection);
                $adapter = new GroupRouteAdapter();
                $this->calling($adapter)->getDoctrineConnection = $probe;
                $this->calling($adapter)->getProvider = $original->getProvider();
                $probes[] = $probe;
                $adapters[] = $adapter;
            }
            $criteria = ['glpi_groups.id' => (int)$group->getID()];
            $DB = $adapters[0];
            $first = LegacyGroup_User::getUserGroups($uid, $criteria);
            $this->array($first)->hasSize(1);
            $this->string($first[0]['comment'])->isIdenticalTo('First route');
            $this->array($probes[0]->queries)->hasSize(1);
            $this->array($probes[1]->queries)->isEmpty();
            $this->integer($connection->update('glpi_groups', ['comment' => 'Second route'], ['id' => $group->getID()]))->isIdenticalTo(1);
            $DB = $adapters[1];
            $second = LegacyGroup_User::getUserGroups($uid, $criteria);
            $this->array($second)->hasSize(1);
            $this->string($second[0]['comment'])->isIdenticalTo('Second route');
            $this->array($probes[0]->queries)->hasSize(1);
            $this->array($probes[1]->queries)->hasSize(1);
        } finally {
            $DB = $original;
            $_SESSION = $session;
        }
    }

    public function testGetGroupUsers()
    {
        global $DB;
        $group = new Group();
        $gid = (int)$group->add([
           'name' => 'Test group'
        ]);
        $this->integer($gid)->isGreaterThan(0);

        $uid1 = (int)getItemByTypeName('User', 'normal', true);
        $uid2 = (int)getItemByTypeName('User', 'tech', true);

        $group_user = $this->newTestedInstance();
        $this->integer(
            (int)$group_user->add([
              'groups_id' => $gid,
              'users_id'  => $uid1
         ])
        );

        $this->integer(
            (int)$group_user->add([
              'groups_id'    => $gid,
              'users_id'     => $uid2,
              'is_manager'   => 1
         ])
        );

        $group_users = LegacyGroup_User::getGroupUsers($gid);
        $this->array($group_users)->hasSize(2);

        $group_users = LegacyGroup_User::getGroupUsers($gid, ['is_manager' => 1]);
        $this->array($group_users)->hasSize(1);
        $this->integer((int)$group_users[0]['id'])->isIdenticalTo($uid2);

        $managers = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $beforeManagers = $managers->getValue();
        for ($repeat = 0; $repeat < 16; ++$repeat) {
            $this->array(LegacyGroup_User::getGroupUsers($gid, ['is_manager' => 1]))->isIdenticalTo($group_users);
        }
        $groupReaderAllocations = $managers->getValue() - $beforeManagers;

        $this->boolean($DB->update('glpi_users', ['firstname' => 'Current group reader'], ['id' => $uid2]))->isTrue();
        $fresh = LegacyGroup_User::getGroupUsers($gid, ['is_manager' => 1]);
        $this->array($fresh)->hasSize(1);
        $this->string($fresh[0]['firstname'])->isIdenticalTo('Current group reader');
        $this->boolean($DB->update('glpi_groups_users', ['is_manager' => 0], ['groups_id' => $gid, 'users_id' => $uid2]))->isTrue();
        $this->array(LegacyGroup_User::getGroupUsers($gid, ['is_manager' => 1]))->isEmpty();
        $this->array(array_map('intval', array_column(
            LegacyGroup_User::getGroupUsers($gid, ['glpi_groups_users.groups_id' => PHP_INT_MAX]),
            'id'
        )))->isIdenticalTo([$uid1, $uid2]);
        $this->exception(static fn () => LegacyGroup_User::getGroupUsers($gid, ['glpi_profiles_users.entities_id' => 0]))
            ->isInstanceOf(UnsupportedCriteria::class);
        $this->array(LegacyGroup_User::getGroupUsers($gid, ['is_manager' => 0]))->hasSize(2);

        //cleanup
        $this->boolean($group->delete(['id' => $gid], true))->isTrue();

        $group_users = LegacyGroup_User::getGroupUsers($gid);
        $this->array($group_users)->hasSize(0);
        $this->integer($groupReaderAllocations)->isIdenticalTo(0);
    }

    public function testGetUserGroups()
    {
        global $DB;
        $uid = (int)getItemByTypeName('User', 'normal', true);

        $group = new Group();
        $gid1 = (int)$group->add([
           'name' => 'Test group'
        ]);
        $this->integer($gid1)->isGreaterThan(0);

        $gid2 = (int)$group->add([
           'name' => 'Test group 2'
        ]);
        $this->integer($gid2)->isGreaterThan(0);

        $group_user = $this->newTestedInstance();
        $this->integer(
            (int)$group_user->add([
              'groups_id' => $gid1,
              'users_id'  => $uid
         ])
        );

        $this->integer(
            (int)$group_user->add([
              'groups_id'    => $gid2,
              'users_id'     => $uid,
              'is_manager'   => 1
         ])
        );

        $group_users = LegacyGroup_User::getUserGroups($uid);
        $this->array($group_users)->hasSize(2);

        $group_users = LegacyGroup_User::getUserGroups($uid, ['glpi_groups_users.is_manager' => 1]);
        $this->array($group_users)->hasSize(1);
        $this->integer((int)$group_users[0]['id'])->isIdenticalTo($gid2);

        $managers = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $beforeManagers = $managers->getValue();
        for ($repeat = 0; $repeat < 16; ++$repeat) {
            $this->array(LegacyGroup_User::getUserGroups($uid, ['glpi_groups_users.is_manager' => 1]))->isIdenticalTo($group_users);
        }
        $userReaderAllocations = $managers->getValue() - $beforeManagers;

        $this->boolean($DB->update('glpi_groups', ['name' => 'Current group reader'], ['id' => $gid2]))->isTrue();
        $fresh = LegacyGroup_User::getUserGroups($uid, ['glpi_groups_users.is_manager' => 1]);
        $this->array($fresh)->hasSize(1);
        $this->string($fresh[0]['name'])->isIdenticalTo('Current group reader');
        $this->boolean($DB->update('glpi_groups_users', ['is_manager' => 0], ['groups_id' => $gid2, 'users_id' => $uid]))->isTrue();
        $this->array(LegacyGroup_User::getUserGroups($uid, ['glpi_groups_users.is_manager' => 1]))->isEmpty();
        $this->array(array_map('intval', array_column(
            LegacyGroup_User::getUserGroups($uid, ['glpi_groups_users.users_id' => PHP_INT_MAX]),
            'id'
        )))->isIdenticalTo([$gid2, $gid1]);
        $this->exception(static fn () => LegacyGroup_User::getUserGroups($uid, ['glpi_profiles_users.entities_id' => 0]))
            ->isInstanceOf(UnsupportedCriteria::class);
        $this->array(LegacyGroup_User::getUserGroups($uid, ['glpi_groups_users.is_manager' => 0]))->hasSize(2);

        //cleanup
        $this->boolean($group_user->deleteByCriteria(['users_id' => $uid]))->isTrue();

        $group_users = LegacyGroup_User::getUserGroups($uid);
        $this->array($group_users)->hasSize(0);
        $this->integer($userReaderAllocations)->isIdenticalTo(0);
    }

    public function testgetListForItemParams()
    {
        $this->newTestedInstance();
        $user = getItemByTypeName('User', TU_USER);

        $expected = [];
        $this->array(iterator_to_array($this->testedInstance->getListForItem($user)))->isIdenticalTo($expected);

        //Now, add groups to user
        $group = new \Group();
        $gid1 = (int)$group->add([
           'name' => 'Test group'
        ]);
        $this->integer($gid1)->isGreaterThan(0);

        $gid2 = (int)$group->add([
           'name' => 'Test group 2'
        ]);
        $this->integer($gid2)->isGreaterThan(0);

        $group_user = $this->newTestedInstance();
        $this->integer(
            (int)$group_user->add([
              'groups_id' => $gid1,
              'users_id'  => $user->getID()
         ])
        );

        $this->integer(
            (int)$group_user->add([
              'groups_id'    => $gid2,
              'users_id'     => $user->getID(),
              'is_manager'   => 1
         ])
        );

        $list_items = iterator_to_array($this->testedInstance->getListForItem($user));
        $this->array($list_items)
           ->hasSize(2)
           ->hasKeys([$gid1, $gid2]);

        $this->array($list_items[$gid1])
           ->hasKey('linkid')
           ->string['name']->isIdenticalTo('Test group');

        $this->array($list_items[$gid2])
           ->hasKey('linkid')
           ->string['name']->isIdenticalTo('Test group 2');

        $group->getFromDB($gid2);
        $list_items = iterator_to_array($this->testedInstance->getListForItem($group));
        $this->array($list_items)
           ->hasSize(1)
           ->hasKey($user->getID());

        $this->array($list_items[$user->getID()])
           ->hasKeys(['linkid', 'is_manager', 'is_userdelegate'])
           ->string['name']->isIdenticalTo('_test_user');

        $this->integer($this->testedInstance->countForItem($user))->isIdenticalTo(2);
        $this->integer($this->testedInstance->countForItem($group))->isIdenticalTo(1);
    }

    public function testIsUserInGroup()
    {
        $group = new \Group();
        // Add a group
        $groups_id = $group->add(
            [
         'name' => __METHOD__,
         'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true)]
        );
        $this->integer((int)$groups_id)->isGreaterThan(0);
        $this->boolean($group->getFromDB($groups_id))->isTrue();

        $group_user = new \Group_User();
        $group_users_id = $group_user->add(
            [
         'groups_id'  => $groups_id,
         'users_id'   => getItemByTypeName('User', 'tech', true),
         'is_dynamic' => 0
      ]
        );
        $this->integer((int)$group_users_id)->isGreaterThan(0);
        $this->boolean($group_user->getFromDB($group_users_id))->isTrue();
        $this->boolean(\Group_User::isUserInGroup(getItemByTypeName('User', 'tech', true), $groups_id))->isTrue();
        $this->boolean(\Group_User::isUserInGroup(getItemByTypeName('User', 'glpi', true), $groups_id))->isFalse();
    }

}

/** Fixed Computer fields isolate route selection from unrelated physical-column discovery. */
class GroupRouteComputer extends Computer
{
    public static ?Closure $onView = null;

    public function __construct()
    {
        $this->fields = ['id' => 0, 'groups_id' => null, 'entities_id' => 0, 'is_template' => 0, 'is_deleted' => 0];
    }

    public static function canView()
    {
        return self::$onView !== null && (self::$onView)(static::class);
    }
}

class GroupRouteComputerSecond extends GroupRouteComputer
{
}
