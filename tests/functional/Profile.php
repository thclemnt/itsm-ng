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
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Profile as ProfileEntity;
use itsmng\Database\Entity\ProfileRight as ProfileRightEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ProfileRightRepository;
use Profile as LegacyProfile;
use ProfileRight;
use ReflectionProperty;
use TypeError;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Entity\Entity as ScopeEntity;
use itsmng\Database\Entity\ProfileUser as ProfileGrant;
use itsmng\Database\Entity\User as UserRecord;
use mock\DBmysql as PermissionRouteAdapter;
use Session;
use stdClass;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/profile.class.php */

class Profile extends DbTestCase
{
    public function testUserPermissionAndDefaultReadsStayCurrentAndPreserveDirtyOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        $connection = $DB->getDoctrineConnection();
        $defaults = $connection->fetchFirstColumn('SELECT id FROM glpi_profiles WHERE is_default = ?', [true], [Types::BOOLEAN]);
        try {
            $this->login();
            $owner = Orm::create($DB);
            $userId = (int)Session::getLoginUserID();
            $scopeId = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $childId = (int)getItemByTypeName('Entity', '_test_child_1', true);
            $profile = new ProfileEntity();
            $profile->name = $this->getUniqueString();
            $other = new ProfileEntity();
            $other->name = $this->getUniqueString();
            $right = new ProfileRightEntity();
            $right->profiles = $profile;
            $right->name = 'permission-' . $this->getUniqueString();
            $right->rights = READ;
            $grant = new ProfileGrant();
            $grant->profiles = $profile;
            $grant->users = $owner->find(UserRecord::class, $userId);
            $grant->entities = $owner->find(ScopeEntity::class, $scopeId);
            $grant->is_recursive = false;
            foreach ([$profile, $other, $right, $grant] as $record) {
                $owner->persist($record);
            }
            $owner->flush();
            $this->boolean(LegacyProfile::haveUserRight($userId, $right->name, READ, $scopeId))->isTrue();
            $this->boolean(LegacyProfile::haveUserRight($userId, $right->name, READ | CREATE, $scopeId))->isTrue();
            $this->boolean(LegacyProfile::haveUserRight($userId, $right->name, 0, $scopeId))->isFalse();
            $this->boolean(LegacyProfile::haveUserRight($userId, $right->name, READ, $childId))->isFalse();
            $this->integer($connection->update('glpi_profiles_users', ['is_recursive' => true], ['id' => $grant->id], ['is_recursive' => Types::BOOLEAN]))->isIdenticalTo(1);
            $this->boolean(LegacyProfile::haveUserRight($userId, $right->name, READ, $childId))->isTrue();
            $this->boolean(LegacyProfile::haveUserRight($userId, $right->name, READ, 0))->isFalse();
            $this->boolean(LegacyProfile::haveUserRight($userId, $right->name, READ, []))->isFalse();
            $this->boolean(LegacyProfile::haveUserRight(0, $right->name, READ, $scopeId))->isFalse();

            $connection->executeStatement('UPDATE glpi_profiles SET is_default = ?', [false], [Types::BOOLEAN]);
            $this->integer(LegacyProfile::getDefault())->isIdenticalTo(0);
            $this->integer($connection->update('glpi_profiles', ['is_default' => true], ['id' => $profile->id], ['is_default' => Types::BOOLEAN]))->isIdenticalTo(1);
            $this->integer(LegacyProfile::getDefault())->isIdenticalTo($profile->id);
            $owner->refresh($profile);
            $profile->is_default = false;
            $right->rights = DELETE;
            $this->integer($connection->update('glpi_profilerights', ['rights' => 0], ['id' => $right->id]))->isIdenticalTo(1);
            $this->boolean(LegacyProfile::haveUserRight($userId, $right->name, READ, $scopeId))->isFalse();
            $this->boolean(LegacyProfile::haveUserRight($userId, $right->name, DELETE, $scopeId))->isFalse();
            $this->integer(LegacyProfile::getDefault())->isIdenticalTo($profile->id);
            $this->integer($connection->update('glpi_profiles', ['is_default' => false], ['id' => $profile->id], ['is_default' => Types::BOOLEAN]))->isIdenticalTo(1);
            $this->integer($connection->update('glpi_profiles', ['is_default' => true], ['id' => $other->id], ['is_default' => Types::BOOLEAN]))->isIdenticalTo(1);
            $this->integer(LegacyProfile::getDefault())->isIdenticalTo($other->id);
            $level = $connection->getTransactionNestingLevel();
            Orm::read($DB, function ($nestedOwner) use ($profile, $other, $right, $userId, $childId): void {
                $nested = $nestedOwner->find(ProfileRightEntity::class, $right->id);
                $nested->rights = READ;
                $nestedProfile = $nestedOwner->find(ProfileEntity::class, $profile->id);
                $nestedProfile->is_default = true;
                $this->boolean(LegacyProfile::haveUserRight($userId, $right->name, READ, $childId))->isFalse();
                $this->integer(LegacyProfile::getDefault())->isIdenticalTo($other->id);
                $this->boolean($nestedOwner->contains($nested))->isTrue();
                $this->integer($nested->rights)->isIdenticalTo(READ);
                $this->boolean($nestedOwner->contains($nestedProfile))->isTrue();
                $this->boolean($nestedProfile->is_default)->isTrue();
            });
            $this->boolean($owner->contains($right))->isTrue();
            $this->integer($right->rights)->isIdenticalTo(DELETE);
            $this->boolean($owner->contains($profile))->isTrue();
            $this->boolean($profile->is_default)->isFalse();
            $this->integer((int)$connection->fetchOne('SELECT rights FROM glpi_profilerights WHERE id = ?', [$right->id]))->isIdenticalTo(0);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $connection->executeStatement('UPDATE glpi_profiles SET is_default = ?', [false], [Types::BOOLEAN]);
            foreach ($defaults as $id) {
                $connection->update('glpi_profiles', ['is_default' => true], ['id' => $id], ['is_default' => Types::BOOLEAN]);
            }
            $_SESSION = $session;
        }
    }

    public function testPermissionPreparationKeepsCustomRouteAndScopeBeforeNameConversion(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        try {
            $this->login('tech', 'tech');
            $connection = $DB->getDoctrineConnection();
            $userId = (int)Session::getLoginUserID();
            $expectedDefault = LegacyProfile::getDefault();
            $expected = LegacyProfile::haveUserRight($userId, 'ticket', CREATE, 0);
            $this->boolean($expected)->isTrue();
            $observer = new class () {
                public array $trace = [];
                public int $clears = 0;

                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $selected = new class ($connection) extends ScalarReadProbe {
                public EventManager $events;
                public object $observer;

                public function getEventManager(): EventManager
                {
                    $this->observer->trace[] = 'constructed';
                    return $this->events;
                }
            };
            $selected->events = new EventManager();
            $selected->events->addEventListener(['onClear'], $observer);
            $selected->observer = $observer;
            $other = new ScalarReadProbe($connection);
            $route = $selected;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new PermissionRouteAdapter();
            $this->calling($adapter)->getDoctrineConnection = static function () use (&$route, $observer): Connection {
                $observer->trace[] = 'resolved';
                return $route;
            };
            $DB = $adapter;
            $this->integer(LegacyProfile::getDefault())->isIdenticalTo($expectedDefault);
            $this->integer($observer->clears)->isIdenticalTo(0);
            $observer->trace = [];
            $name = new class ($this, $connection, $observer, $other, $route) {
                public function __construct(
                    private object $test,
                    private Connection $connection,
                    private object $observer,
                    private Connection $other,
                    private Connection &$route
                ) {
                }

                public function __toString(): string
                {
                    $this->test->boolean($this->connection->isApplicationEntityManagerActive())->isFalse();
                    $this->observer->trace[] = 'converted';
                    $this->route = $this->other;
                    return 'ticket';
                }
            };
            $this->boolean(LegacyProfile::haveUserRight($userId, $name, CREATE, 0))->isIdenticalTo($expected);
            $this->array(array_slice($observer->trace, 0, 2))->isIdenticalTo(['resolved', 'constructed']);
            $conversion = array_search('converted', $observer->trace, true);
            $scopeResolution = array_search('resolved', array_slice($observer->trace, 2), true);
            $this->variable($scopeResolution)->isNotIdenticalTo(false);
            $this->integer($conversion)->isGreaterThan($scopeResolution + 2);
            $this->string(end($observer->trace))->isIdenticalTo('converted');
            $permissionQueries = array_filter(
                $selected->queries,
                static fn (array $query): bool => str_contains($query['sql'], 'glpi_profilerights'),
            );
            $this->array($permissionQueries)->hasSize(1);
            $this->array($other->queries)->isEmpty();
            $route = $selected;
            $clears = $observer->clears;
            $this->exception(static fn () => LegacyProfile::haveUserRight($userId, new stdClass(), CREATE, []))
                ->isInstanceOf(TypeError::class);
            $this->integer($observer->clears)->isIdenticalTo($clears);
            $this->boolean($connection->isApplicationEntityManagerActive())->isFalse();
            $this->boolean(LegacyProfile::haveUserRight($userId, 'ticket', CREATE, []))->isFalse();
            $this->integer(LegacyProfile::getDefault())->isIdenticalTo($expectedDefault);
        } finally {
            $DB = $original;
            $_SESSION = $session;
        }
    }

    public function testPermissionReadsReuseScopeAndObserveWrites()
    {
        global $DB, $GLPI_CACHE;

        $session = $_SESSION;
        $hadCache = $GLPI_CACHE->has('all_possible_rights');
        $cached = $hadCache ? $GLPI_CACHE->get('all_possible_rights') : null;
        $connection = $DB->getDoctrineConnection();
        $renamed = '__test_permission_scope_computer';
        $right = false;
        try {
            $this->login('tech', 'tech');
            $profile = getItemByTypeName('Profile', 'Technician');
            $id = (int)$profile->getID();
            $external = Orm::forConnection($connection);
            $entity = $external->find(ProfileEntity::class, $id);
            $expected = [];
            foreach ((new ProfileRightRepository($external))->names() as $name) {
                $expected[$name] = '';
            }
            $right = $connection->fetchOne('SELECT rights FROM glpi_profilerights WHERE profiles_id = ? AND name = ?', [$id, 'computer']);
            Orm::withReadConnection($connection, static function (?EntityManager $manager): void {
                $manager->getClassMetadata(ProfileRightEntity::class);
                $manager->getClassMetadata(ProfileEntity::class);
            });
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $before = $factories->getValue();
            ProfileRight::cleanAllPossibleRights();
            $this->array(ProfileRight::getAllPossibleRights())->isIdenticalTo($expected);
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            $connection->update('glpi_profilerights', ['name' => $renamed], ['name' => 'computer']);
            $this->array(ProfileRight::getAllPossibleRights())->isIdenticalTo($expected, 'Existing cache policy remains in effect');
            ProfileRight::cleanAllPossibleRights();
            $fresh = ProfileRight::getAllPossibleRights();
            $this->array($fresh)->hasKey($renamed)->notHasKey('computer');
            $connection->update('glpi_profilerights', ['name' => 'computer'], ['name' => $renamed]);

            // One known right keeps the public permission decision independent of fixture-wide grants.
            $GLPI_CACHE->set('all_possible_rights', ['computer' => '']);
            $_SESSION['glpiactiveprofile'] = ['interface' => 'central', 'profile' => 0, 'computer' => READ];
            unset($_SESSION['glpicronuserrunning']);
            $connection->update('glpi_profilerights', ['rights' => 0], ['profiles_id' => $id, 'name' => 'computer']);
            $this->boolean(LegacyProfile::currentUserHaveMoreRightThan([$id, (string)$id]))->isTrue();
            $connection->update('glpi_profilerights', ['rights' => READ | CREATE], ['profiles_id' => $id, 'name' => 'computer']);
            $this->boolean(LegacyProfile::currentUserHaveMoreRightThan([$id]))->isFalse('A later SQL write is visible through the reused manager');
            $_SESSION['glpiactiveprofile']['computer'] = READ | CREATE;
            $this->boolean(LegacyProfile::currentUserHaveMoreRightThan([$id]))->isTrue();
            $this->boolean(LegacyProfile::currentUserHaveMoreRightThan([$id, PHP_INT_MAX]))->isFalse();
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0, 'Repeated scalar permission reads keep the same manager');
            $this->boolean($external->contains($entity))->isTrue('An independent caller manager is never cleared');

            Orm::withReadConnection($connection, function (?EntityManager $outer) use ($connection, $id, $factories): void {
                $managed = $outer->find(ProfileEntity::class, $id);
                $beforeNested = $factories->getValue();
                $this->boolean(LegacyProfile::currentUserHaveMoreRightThan([$id]))->isTrue();
                $this->integer($factories->getValue() - $beforeNested)->isIdenticalTo(1, 'Reentrant work retains an independent unit of work');
                $this->boolean($outer->contains($managed))->isTrue();
                $this->boolean($connection->ownsApplicationEntityManager($outer))->isTrue();
            });
        } finally {
            $connection->update('glpi_profilerights', ['name' => 'computer'], ['name' => $renamed]);
            if ($right !== false) {
                $connection->update('glpi_profilerights', ['rights' => $right], ['profiles_id' => $id, 'name' => 'computer']);
            }
            $_SESSION = $session;
            if ($hadCache) {
                $GLPI_CACHE->set('all_possible_rights', $cached);
            } else {
                $GLPI_CACHE->delete('all_possible_rights');
            }
        }
    }

    /**
     * @see self::testHaveUserRight()
     *
     * @return array
     */
    protected function haveUserRightProvider()
    {

        return [
           [
              'user'     => [
                 'login'    => 'post-only',
                 'password' => 'postonly',
              ],
              'rightset' => [
                 ['name' => \Computer::$rightname, 'value' => CREATE, 'expected' => false],
                 ['name' => \Computer::$rightname, 'value' => DELETE, 'expected' => false],
                 ['name' => \Ticket::$rightname, 'value' => CREATE, 'expected' => true],
                 ['name' => \Ticket::$rightname, 'value' => DELETE, 'expected' => false],
                 ['name' => \ITILFollowup::$rightname, 'value' => \ITILFollowup::ADDMYTICKET, 'expected' => true],
                 ['name' => \ITILFollowup::$rightname, 'value' => \ITILFollowup::ADDALLTICKET, 'expected' => false],
              ],
           ],
           [
              'user'     => [
                 'login'    => 'itsm',
                 'password' => 'itsm',
              ],
              'rightset' => [
                 ['name' => \Computer::$rightname, 'value' => CREATE, 'expected' => true],
                 ['name' => \Computer::$rightname, 'value' => DELETE, 'expected' => true],
                 ['name' => \Ticket::$rightname, 'value' => CREATE, 'expected' => true],
                 ['name' => \Ticket::$rightname, 'value' => DELETE, 'expected' => true],
                 ['name' => \ITILFollowup::$rightname, 'value' => \ITILFollowup::ADDMYTICKET, 'expected' => true],
                 ['name' => \ITILFollowup::$rightname, 'value' => \ITILFollowup::ADDALLTICKET, 'expected' => true],
              ],
           ],
           [
              'user'     => [
                 'login'    => 'tech',
                 'password' => 'tech',
              ],
              'rightset' => [
                 ['name' => \Computer::$rightname, 'value' => CREATE, 'expected' => true],
                 ['name' => \Computer::$rightname, 'value' => DELETE, 'expected' => true],
                 ['name' => \Ticket::$rightname, 'value' => CREATE, 'expected' => true],
                 ['name' => \Ticket::$rightname, 'value' => DELETE, 'expected' => false],
                 ['name' => \ITILFollowup::$rightname, 'value' => \ITILFollowup::ADDMYTICKET, 'expected' => true],
                 ['name' => \ITILFollowup::$rightname, 'value' => \ITILFollowup::ADDALLTICKET, 'expected' => true],
              ],
           ],
        ];
    }

    /**
     * Tests user rights checking.
     *
     * @param array   $user     Array containing 'login' and 'password' fields of tested user.
     * @param array   $rightset Array of arrays containing 'name', 'value' and 'expected' result of a right.
     *
     * @dataProvider haveUserRightProvider
     */
    public function testHaveUserRight(array $user, array $rightset)
    {

        $this->login($user['login'], $user['password']);

        foreach ($rightset as $rightdata) {
            $result = \Profile::haveUserRight(
                \Session::getLoginUserID(),
                $rightdata['name'],
                $rightdata['value'],
                0
            );
            $this->boolean($result)
               ->isEqualTo(
                   $rightdata['expected'],
                   sprintf('Unexpected result for value "%d" of "%s" right.', $rightdata['value'], $rightdata['name'])
               );
        }
    }

    /**
     * We try to login with tech profile and check if we can get a super-admin profile
     */
    public function testAssignableProfileIdsStayCurrentAndKeepCallerOwnership(): void
    {
        global $DB, $GLPI_CACHE;
        $session = $_SESSION;
        $hadCache = $GLPI_CACHE->has('all_possible_rights');
        $cached = $hadCache ? $GLPI_CACHE->get('all_possible_rights') : null;
        $connection = $DB->getDoctrineConnection();
        $external = null;
        $right = false;
        try {
            $this->login('tech', 'tech');
            $profile = getItemByTypeName('Profile', 'Technician');
            $id = (int)$profile->getID();
            $right = $connection->fetchOne('SELECT rights FROM glpi_profilerights WHERE profiles_id = ? AND name = ?', [$id, 'computer']);
            $GLPI_CACHE->set('all_possible_rights', ['computer' => '']);
            $_SESSION['glpiactiveprofile'] = ['interface' => 'central', 'profile' => 0, 'computer' => READ];
            unset($_SESSION['glpicronuserrunning']);
            $external = Orm::forConnection($connection);
            $entity = $external->find(ProfileEntity::class, $id);
            $connection->update('glpi_profilerights', ['rights' => 0], ['profiles_id' => $id, 'name' => 'computer']);
            $this->array(LegacyProfile::getUnderActiveProfileRestrictCriteria()['glpi_profiles.id'])->contains($id);
            $connection->update('glpi_profilerights', ['rights' => READ | CREATE], ['profiles_id' => $id, 'name' => 'computer']);
            $this->array(LegacyProfile::getUnderActiveProfileRestrictCriteria()['glpi_profiles.id'])->notContains($id);
            $_SESSION['glpiactiveprofile']['computer'] = READ | CREATE;
            $this->array(LegacyProfile::getUnderActiveProfileRestrictCriteria()['glpi_profiles.id'])->contains($id);
            $this->boolean($external->contains($entity))->isTrue();
            Orm::withReadConnection($connection, function (?EntityManager $outer) use ($id): void {
                $managed = $outer->find(ProfileEntity::class, $id);
                $name = $managed->name . '-unflushed';
                $managed->name = $name;
                $this->array(LegacyProfile::getUnderActiveProfileRestrictCriteria()['glpi_profiles.id'])->contains($id);
                $this->boolean($outer->contains($managed))->isTrue();
                $this->string($managed->name)->isIdenticalTo($name);
            });
            $interface = new class () {
                public int $conversions = 0;
                public function __toString(): string
                {
                    ++$this->conversions;
                    return 'central';
                }
            };
            $_SESSION['glpiactiveprofile']['computer'] = READ;
            $_SESSION['glpiactiveprofile']['interface'] = $interface;
            // The uncoerced first getter drops computer; an empty right set admits this central profile.
            // Coercing that getter early would compare READ against the stored READ | CREATE and exclude it.
            $this->array(LegacyProfile::getUnderActiveProfileRestrictCriteria()['glpi_profiles.id'])->contains($id);
            $this->integer($interface->conversions)->isIdenticalTo(1);
            $_SESSION['glpiactiveprofile']['interface'] = false;
            $this->array(LegacyProfile::getUnderActiveProfileRestrictCriteria())->isIdenticalTo(['glpi_profiles.id' => ['<', 0]]);
            $_SESSION['glpiactiveprofile']['interface'] = [];
            $this->exception(fn () => LegacyProfile::getUnderActiveProfileRestrictCriteria())->isInstanceOf(TypeError::class);
            unset($_SESSION['glpiactiveprofile']);
            $this->array(LegacyProfile::getUnderActiveProfileRestrictCriteria())->isIdenticalTo(['glpi_profiles.id' => ['<', 0]]);
            $_SESSION['glpiactiveprofile'] = ['interface' => 'central', 'profile' => CREATE];
            $this->array(LegacyProfile::getUnderActiveProfileRestrictCriteria())->isEmpty();
        } finally {
            if ($right !== false) {
                $connection->update('glpi_profilerights', ['rights' => $right], ['profiles_id' => $id, 'name' => 'computer']);
            }
            $external?->clear();
            $_SESSION = $session;
            if ($hadCache) {
                $GLPI_CACHE->set('all_possible_rights', $cached);
            } else {
                $GLPI_CACHE->delete('all_possible_rights');
            }
        }
    }

    public function testGetUnderActiveProfileRestrictCriteria()
    {
        global $DB;

        $this->login('tech', 'tech');

        $iterator = $DB->request([
           'FROM'   => \Profile::getTable(),
           'WHERE'  => \Profile::getUnderActiveProfileRestrictCriteria(),
           'ORDER'  => 'name'
        ]);

        foreach ($iterator as $profile_found) {
            $this->array($profile_found)->string['name']->isNotEqualTo('Super-Admin');
            $this->array($profile_found)->string['name']->isNotEqualTo('Admin');
        }
    }

    /**
     * Check we keep only necessary rights (at least for ticket)
     * when passing a profile from standard to self-service interface
     */
    public function testSwitchingInterface()
    {
        $ticket = new \Ticket();

        //create a temporay standard profile
        $profile = new \Profile();
        $profiles_id = $profile->add([
           'name'      => "test switch profile",
           'interface' => "standard",
        ]);

        // retrieve all tickets rights
        $all_rights = $ticket->getRights();
        $all_rights = array_keys($all_rights);
        $all_rights = array_fill_keys($all_rights, 1);

        // add all ticket rights to this profile
        $profile->update([
           'id'      => $profiles_id,
           '_ticket' => $all_rights
        ]);

        // switch to self-service interface
        $profile->update([
           'id'        => $profiles_id,
           'interface' => 'helpdesk'
        ]);

        // retrieve self-service tickets rights
        $ss_rights = $ticket->getRights("helpdesk");
        $ss_rights = array_keys($ss_rights);
        $ss_rights = array_fill_keys($ss_rights, 1);
        $exc_rights = array_diff_key($all_rights, $ss_rights);

        //reload profile
        $profile->getFromDB($profiles_id);

        // check removed rights is clearly removed
        foreach ($exc_rights as $right => $value) {
            $this->integer(($profile->fields['ticket'] & $right))->isEqualTo(0);
        }
        // check self-service rights is still here
        foreach ($ss_rights as $right => $value) {
            $this->integer(($profile->fields['ticket'] & $right))->isEqualTo($right);
        }

    }


    public function testCloneCopiesRights()
    {
        $profile = new \Profile();
        $profiles_id = $profile->add([
           'name'      => 'Profile to clone',
           'interface' => 'standard',
        ]);
        $this->integer($profiles_id)->isGreaterThan(0);

        \ProfileRight::updateProfileRights($profiles_id, [
           'computer' => READ | CREATE | UPDATE,
           'ticket'   => READ | CREATE | UPDATE | DELETE,
        ]);
        $this->boolean($profile->getFromDB($profiles_id))->isTrue();

        $cloned_profiles_id = $profile->clone();
        $this->integer($cloned_profiles_id)->isGreaterThan($profiles_id);

        $source_rights = \ProfileRight::getProfileRights($profiles_id);
        $cloned_rights = \ProfileRight::getProfileRights($cloned_profiles_id);
        ksort($source_rights);
        ksort($cloned_rights);

        $this->array($cloned_rights)->isIdenticalTo($source_rights);
    }
}
