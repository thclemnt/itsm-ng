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

/* Test for inc/profile.class.php */

class Profile extends DbTestCase
{
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
