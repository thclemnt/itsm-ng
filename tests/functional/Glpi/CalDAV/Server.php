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

namespace tests\units\Glpi\CalDAV;

use DbTestCase;
use Auth;
use AuthLDAP;
use Closure;
use Computer;
use Doctrine\DBAL\Exception as DbalException;
use Glpi\CalDAV\Backend\Principal;
use Group;
use Group_User as GroupUser;
use itsmng\Database\Entity\Computer as MappedComputer;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use mock\DBmysql as PrincipalRouteAdapter;
use Sabre\HTTP\Response;
use tests\fixtures\ScalarReadProbe;
use User;

require_once dirname(__DIR__, 3) . '/fixtures/ScalarReadProbe.php';


/* Test for inc/glpi/caldav/server.class.php */

class Server extends DbTestCase
{
    public function testPrincipalRelationsKeepTaskEntityScopeAndHttpAcl(): void
    {
        global $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $this->setEntity(0, true);
            $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $parent = $this->createItem(Group::class, [
                'name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_task' => 1,
            ]);
            $child = $this->createItem(Group::class, [
                'name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_task' => 1, 'groups_id' => $parent->getID(),
            ]);
            $this->createItem(Group::class, [
                'name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_task' => 0, 'groups_id' => $parent->getID(),
            ]);
            $recursive = $this->createItem(Group::class, [
                'name' => $this->getUniqueString(), 'entities_id' => 0,
                'is_recursive' => 1, 'is_task' => 1, 'groups_id' => $parent->getID(),
            ]);
            $this->createItem(Group::class, [
                'name' => $this->getUniqueString(), 'entities_id' => 0,
                'is_recursive' => 0, 'is_task' => 1, 'groups_id' => $parent->getID(),
            ]);
            $tech = (int)getItemByTypeName(User::class, 'tech', true);
            $this->createItem(GroupUser::class, ['groups_id' => $parent->getID(), 'users_id' => $tech]);
            $_SESSION['glpiactiveentities'] = [$entity];
            $backend = new Principal();
            $path = Principal::PREFIX_GROUPS . '/' . $parent->getID();
            $groups = [Principal::PREFIX_GROUPS . '/' . $child->getID(), Principal::PREFIX_GROUPS . '/' . $recursive->getID()];
            $actual = $backend->getGroupMembership($path);
            sort($actual);
            sort($groups);
            $this->array($actual)->isIdenticalTo($groups);
            $members = $backend->getGroupMemberSet($path);
            $this->string(array_pop($members))->isIdenticalTo(Principal::PREFIX_USERS . '/tech');
            sort($members);
            $this->array($members)->isIdenticalTo($groups);
            $this->array($backend->getGroupMembership(Principal::PREFIX_USERS . '/tech'))
                ->contains(Principal::PREFIX_GROUPS . '/' . $parent->getID());
            $this->array($backend->getGroupMemberSet(Principal::PREFIX_USERS . '/tech'))->isEmpty();
            $this->array($backend->getGroupMembership('principals/unknown/123'))->isEmpty();
            $this->array($backend->getGroupMembership(Principal::PREFIX_GROUPS . '/0'))->isEmpty();
            $this->array($backend->getGroupMembership(Principal::PREFIX_GROUPS . '/NULL'))
                ->contains(Principal::PREFIX_GROUPS . '/' . $parent->getID());
            $_SESSION['glpiactiveentities'] = [];
            $this->array($backend->getGroupMembership(Principal::PREFIX_USERS . '/tech'))->isEmpty();
            $this->array($backend->getGroupMemberSet($path))->isIdenticalTo([Principal::PREFIX_USERS . '/tech']);

            // Actual HTTP authorization stays separate from relation filtering.
            $this->setEntity(0, true);
            $httpGroup = $this->createItem(Group::class, ['name' => $this->getUniqueString(), 'entities_id' => 0, 'is_task' => 1]);
            $this->createItem(GroupUser::class, ['groups_id' => $httpGroup->getID(), 'users_id' => $tech]);
            $httpPath = Principal::PREFIX_GROUPS . '/' . $httpGroup->getID();
            foreach (['normal' => 'HTTP/1.1 403 Forbidden', 'tech' => 'HTTP/1.1 200 OK'] as $login => $status) {
                $this->login($login, $login);
                $server = $this->getServerInstance('PROPFIND', $httpPath);
                $server->setBaseUri($server->httpRequest->getBaseUrl());
                $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $login));
                $server->httpRequest->setBody('<d:propfind xmlns:d="DAV:"><d:prop><d:group-member-set/><d:group-membership/></d:prop></d:propfind>');
                $response = new Response();
                $server->invokeMethod($server->httpRequest, $response, false);
                $this->validateResponseIsOk($response, 207, 'application/xml');
                $xpath = $this->getXpathFromResponse($response);
                $this->string($xpath->evaluate('string(/d:multistatus/d:response[1]/d:propstat/d:status)'))->isIdenticalTo($status);
                if ($login === 'tech') {
                    $this->integer((int)$xpath->evaluate('count(/d:multistatus/d:response[1]/d:propstat/d:prop/d:group-member-set/d:href[contains(., "principals/users/tech/")])'))->isIdenticalTo(1);
                }
            }
        } finally {
            $_SESSION = $session;
        }
    }

    public function testPrincipalRelationsReadCurrentRowsAfterCallbacksAndRetainDirtyOwners(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $this->setEntity(0, true);
            $parent = $this->createItem(Group::class, ['name' => $this->getUniqueString(), 'entities_id' => 0, 'is_task' => 1]);
            $child = $this->createItem(Group::class, [
                'name' => $this->getUniqueString(), 'entities_id' => 0, 'is_task' => 1, 'groups_id' => $parent->getID(),
            ]);
            $computer = $this->createItem(Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
            $tech = (int)getItemByTypeName(User::class, 'tech', true);
            $connection = $original->getDoctrineConnection();
            $level = $connection->getTransactionNestingLevel();
            $owner = Orm::create($original);
            $dirty = $owner->find(MappedComputer::class, (int)$computer->getID());
            $dirty->name = 'Unflushed CalDAV owner';
            $backend = new PrincipalCallbackProbe();
            $_SESSION['glpiactiveentities'] = [0];
            $first = new ScalarReadProbe($connection);
            $second = new ScalarReadProbe($connection);
            $this->mockGenerator()->orphanize('__construct');
            $firstAdapter = new PrincipalRouteAdapter();
            $secondAdapter = new PrincipalRouteAdapter();
            $resolutions = [0, 0];
            $this->calling($firstAdapter)->getDoctrineConnection = static function () use ($first, &$resolutions) {
                ++$resolutions[0];
                return $first;
            };
            $this->calling($secondAdapter)->getDoctrineConnection = static function () use ($second, &$resolutions) {
                ++$resolutions[1];
                return $second;
            };
            $backend->onGroupUri = function ($id) use ($connection, $parent, $child, $tech, $secondAdapter, $original): void {
                $this->variable($id)->isEqualTo($child->getID());
                $this->boolean($connection->isApplicationEntityManagerActive())->isFalse();
                $GLOBALS['DB'] = $original;
                $this->createItem(GroupUser::class, ['groups_id' => $parent->getID(), 'users_id' => $tech]);
                $GLOBALS['DB'] = $secondAdapter;
            };
            $DB = $firstAdapter;
            $path = Principal::PREFIX_GROUPS . '/' . $parent->getID();
            $this->array($backend->getGroupMemberSet($path))->isIdenticalTo([
                Principal::PREFIX_GROUPS . '/' . $child->getID(), Principal::PREFIX_USERS . '/tech',
            ]);
            $this->integer($resolutions[0])->isGreaterThanOrEqualTo(1);
            $this->integer($resolutions[1])->isIdenticalTo(1);
            $groupReads = array_filter(
                $first->queries,
                static fn (array $query): bool => str_contains(str_replace(['`', '"'], '', $query['sql']), 'FROM glpi_groups g'),
            );
            $this->array($groupReads)->hasSize(1);
            $this->array($second->queries)->hasSize(1);
            $this->array($backend->events)->isIdenticalTo(['type', 'group-id', 'type', 'group-uri', 'user-uri']);
            $backend->onGroupUri = null;
            $atParser = null;
            $backend->onType = static function () use ($secondAdapter, &$atParser, &$resolutions): void {
                $atParser = $resolutions;
                $GLOBALS['DB'] = $secondAdapter;
            };
            $DB = $firstAdapter;
            $this->array($backend->getGroupMembership(Principal::PREFIX_USERS . '/tech'))
                ->contains(Principal::PREFIX_GROUPS . '/' . $parent->getID());
            $this->array($resolutions)->isIdenticalTo([$atParser[0], $atParser[1] + 1]);
            $DB = $original;
            $backend->onType = null;
            $backend->parentIds = [(int)$parent->getID()];
            $this->array($backend->getGroupMemberSet($path))->hasSize(2);
            $backend->parentIds = null;
            Orm::read($original, function ($nestedOwner) use ($backend, $path, $computer): void {
                $nested = $nestedOwner->find(MappedComputer::class, (int)$computer->getID());
                $nested->name = 'Nested dirty CalDAV owner';
                $this->array($backend->getGroupMemberSet($path))->hasSize(2);
                $this->boolean($nestedOwner->contains($nested))->isTrue();
                $this->string($nested->name)->isIdenticalTo('Nested dirty CalDAV owner');
            });
            $this->boolean($owner->contains($dirty))->isTrue();
            $this->string($dirty->name)->isIdenticalTo('Unflushed CalDAV owner');
            $this->variable($connection->fetchOne(
                'SELECT name FROM glpi_computers WHERE id = ?',
                [$computer->getID()]
            ))->isIdenticalTo($computer->fields['name']);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $DB = $original;
            $_SESSION = $session;
        }
    }

    public function testPrincipalUsernameBindingsAndAmbiguityFailClosed(): void
    {
        global $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $this->setEntity(0, true);
            $group = $this->createItem(Group::class, ['name' => $this->getUniqueString(), 'entities_id' => 0, 'is_task' => 1]);
            $name = "dav'" . bin2hex(random_bytes(6));
            $user = $this->createItem(User::class, ['name' => addslashes($name)]);
            $this->createItem(GroupUser::class, ['groups_id' => $group->getID(), 'users_id' => $user->getID()]);
            $backend = new Principal();
            $expected = [Principal::PREFIX_GROUPS . '/' . $group->getID()];
            $this->array($backend->getGroupMembership(Principal::PREFIX_USERS . '/' . $name))->isIdenticalTo($expected);
            $this->array($backend->getGroupMemberSet(Principal::PREFIX_GROUPS . '/' . $group->getID()))
                ->isIdenticalTo([Principal::PREFIX_USERS . '/' . $name]);
            // Backslashes are rejected by public login creation, but stored imported names remain readable.
            $connection = $DB->getDoctrineConnection();
            $imported = $name . '\\imported';
            $this->integer($connection->update('glpi_users', ['name' => $imported], ['id' => $user->getID()]))->isIdenticalTo(1);
            $this->array($backend->getGroupMembership(Principal::PREFIX_USERS . '/' . $imported))->isIdenticalTo($expected);
            $this->array($backend->getGroupMembership(Principal::PREFIX_USERS . '/' . $name))->isEmpty();
            $this->integer($connection->update('glpi_users', ['name' => $name], ['id' => $user->getID()]))->isIdenticalTo(1);
            $defaultDirectory = AuthLDAP::getNumberOfServers() === 0 ? 1 : 0;
            $ldap = $this->createItem(AuthLDAP::class, ['name' => $this->getUniqueString(), 'is_active' => 0, 'is_default' => $defaultDirectory]);
            $other = $this->createItem(User::class, [
                'name' => addslashes($name), 'authtype' => Auth::LDAP, 'authldaps_id' => $ldap->getID(),
            ]);
            $this->createItem(GroupUser::class, ['groups_id' => $group->getID(), 'users_id' => $other->getID()]);
            $this->array($backend->getGroupMemberSet(Principal::PREFIX_GROUPS . '/' . $group->getID()))
                ->isIdenticalTo([Principal::PREFIX_USERS . '/' . $name, Principal::PREFIX_USERS . '/' . $name]);
            $frame = OwnedMutationFrame::begin($connection);
            try {
                $this->exception(static fn () => $backend->getGroupMembership(Principal::PREFIX_USERS . '/' . $name))
                    ->isInstanceOf(DbalException::class);
            } finally {
                $frame->rollBack();
            }
            $this->array($backend->getGroupMemberSet(Principal::PREFIX_GROUPS . '/' . $group->getID()))->hasSize(2);
        } finally {
            $_SESSION = $session;
        }
    }

    protected function propfindMainEndpointsProvider()
    {
        $dataset = [];

        $group = new \Group();
        $group_1_id = (int)$group->add([
           'name' => 'Test group 1'
        ]);
        $this->integer($group_1_id)->isGreaterThan(0);
        $group_2_id = (int)$group->add([
           'name' => 'Test group 2'
        ]);
        $this->integer($group_2_id)->isGreaterThan(0);

        $users = [
           getItemByTypeName('User', 'itsm', true) => [
              'name'   => 'itsm',
              'pass'   => 'itsm',
              'groups' => [$group_1_id, $group_2_id],
           ],
           getItemByTypeName('User', 'tech', true) => [
              'name'   => 'tech',
              'pass'   => 'tech',
              'groups' => [$group_1_id],
           ],
           getItemByTypeName('User', 'normal', true) => [
              'name'   => 'normal',
              'pass'   => 'normal',
              'groups' => [$group_2_id],
           ],
           getItemByTypeName('User', 'post-only', true) => [
              'name'   => 'post-only',
              'pass'   => 'postonly',
              'groups' => [],
           ],
           getItemByTypeName('User', TU_USER, true) => [
              'name'   => TU_USER,
              'pass'   => TU_PASS,
              'groups' => [],
           ],
        ];

        ksort($users);

        // Create test groups
        foreach ($users as $user_id => $user_data) {
            foreach ($user_data['groups'] as $group_id) {
                $group_user = new \Group_User();
                $this->integer(
                    (int)$group_user->add([
                      'groups_id' => $group_id,
                      'users_id'  => $user_id,
               ])
                )->isGreaterThan(0);
            }
        }

        foreach ($users as $user_data) {
            // All users should be able to get "/", "principals/" and "calendars/" collections properties
            // and should receive same result.
            $dataset[] = [
               'path' => '/',
               'expected_results' => [
                  [
                     'href'         => '',
                     'resourcetype' => 'd:collection',
                  ],
                  [
                     'href'         => 'principals/',
                     'resourcetype' => 'd:collection',
                  ],
                  [
                     'href'         => 'calendars/',
                     'resourcetype' => 'd:collection',
                  ]
               ],
               'login' => $user_data['name'],
               'pass' => $user_data['pass'],
            ];
            $dataset[] = [
               'path' => 'principals/',
               'expected_results' => [
                  [
                     'href'         => 'principals/',
                     'resourcetype' => 'd:collection',
                  ],
                  [
                     'href'         => 'principals/groups/',
                     'resourcetype' => 'd:collection',
                  ],
                  [
                     'href'         => 'principals/users/',
                     'resourcetype' => 'd:collection',
                  ]
               ],
               'login' => $user_data['name'],
               'pass' => $user_data['pass'],
            ];
            $dataset[] = [
               'path' => 'calendars/',
               'expected_results' => [
                  [
                     'href'         => 'calendars/',
                     'resourcetype' => 'd:collection',
                  ],
                  [
                     'href'         => 'calendars/groups/',
                     'resourcetype' => 'd:collection',
                  ],
                  [
                     'href'         => 'calendars/users/',
                     'resourcetype' => 'd:collection',
                  ]
               ],
               'login' => $user_data['name'],
               'pass' => $user_data['pass'],
            ];
        }

        // All users should be able to get "groups" principals properties
        // but result will only contains data for user groups.
        foreach ($users as $user_data) {
            $groups_expected_results = [
               [
                  'href'         => 'principals/groups/',
                  'resourcetype' => 'd:collection',
               ],
            ];
            foreach ($user_data['groups'] as $group_id) {
                // Group principal should be listed in 'principals/groups/' result
                $groups_expected_results[] = [
                   'href'         => 'principals/groups/' . $group_id . '/',
                   'resourcetype' => 'd:principal',
                ];

                // Group principal properties should be accessible at 'principals/groups/$group_id/'
                $dataset[] = [
                   'path' => 'principals/groups/' . $group_id . '/',
                   'expected_results' => [
                      [
                         'href'         => 'principals/groups/' . $group_id . '/',
                         'resourcetype' => 'd:principal',
                      ],
                   ],
                   'login' => $user_data['name'],
                   'pass' => $user_data['pass'],
                ];
            }
            $dataset[] = [
               'path' => 'principals/groups/',
               'expected_results' => $groups_expected_results,
               'login' => $user_data['name'],
               'pass' => $user_data['pass'],
            ];
        }

        // 'glpi' user can see all users principals
        $expected_results = [
           [
              'href'         => 'principals/users/',
              'resourcetype' => 'd:collection',
           ]
        ];
        foreach ($users as $user_data) {
            $expected_results[] = [
               'href'         => 'principals/users/' . $user_data['name'] . '/',
               'resourcetype' => 'd:principal',
            ];
        }
        $dataset[] = [
           'path'             => 'principals/users/',
           'expected_results' => $expected_results,
           'login'            => 'itsm',
           'pass'             => 'itsm',
        ];

        // 'tech', 'normal', 'post-only' user can see only themselves in principals
        foreach (['tech', 'normal', 'post-only'] as $user_name) {
            $user_id   = getItemByTypeName('User', $user_name, true);
            $user_data = $users[$user_id];

            $dataset[] = [
               'path' => 'principals/users/',
               'expected_results' => [
                  [
                     'href'         => 'principals/users/',
                     'resourcetype' => 'd:collection',
                  ],
                  [
                     'href'         => 'principals/users/' . $user_data['name'] . '/',
                     'resourcetype' => 'd:principal',
                  ],
               ],
               'login' => $user_data['name'],
               'pass' => $user_data['pass'],
            ];
        }

        // 'glpi' user can see all users calendars
        $expected_results = [
           [
              'href'         => 'calendars/users/',
              'resourcetype' => 'd:collection',
           ]
        ];
        foreach ($users as $user_data) {
            $expected_results[] = [
               'href'         => 'calendars/users/' . $user_data['name'] . '/',
               'resourcetype' => 'd:collection',
            ];
        }
        $dataset[] = [
           'path'             => 'calendars/users/',
           'expected_results' => $expected_results,
           'login'            => 'itsm',
           'pass'             => 'itsm',
        ];
        foreach ($users as $user_data) {
            $dataset[] = [
               'path'             => 'calendars/users/' . $user_data['name'] . '/',
               'expected_results' => [
                  [
                     'href'         => 'calendars/users/' . $user_data['name'] . '/',
                     'resourcetype' => 'd:collection',
                  ],
                  [
                     'href'         => 'calendars/users/' . $user_data['name'] . '/calendar/',
                     'resourcetype' => ['d:collection', 'cal:calendar'],
                  ]
               ],
               'login'            => 'itsm',
               'pass'             => 'itsm',
            ];
        }

        // 'tech', 'normal', 'post-only' user can see only themselves in calendars
        foreach (['tech', 'normal', 'post-only'] as $user_name) {
            $user_id   = getItemByTypeName('User', $user_name, true);
            $user_data = $users[$user_id];

            $dataset[] = [
               'path' => 'calendars/users/',
               'expected_results' => [
                  [
                     'href'         => 'calendars/users/',
                     'resourcetype' => 'd:collection',
                  ],
                  [
                     'href'         => 'calendars/users/' . $user_data['name'] . '/',
                     'resourcetype' => 'd:collection',
                  ],
               ],
               'login' => $user_data['name'],
               'pass' => $user_data['pass'],
            ];
            $dataset[] = [
               'path' => 'calendars/users/' . $user_data['name'] . '/',
               'expected_results' => [
                  [
                     'href'         => 'calendars/users/' . $user_data['name'] . '/',
                     'resourcetype' => 'd:collection',
                  ],
                  [
                     'href'         => 'calendars/users/' . $user_data['name'] . '/calendar/',
                     'resourcetype' => ['d:collection', 'cal:calendar'],
                  ]
               ],
               'login' => $user_data['name'],
               'pass' => $user_data['pass'],
            ];
        }

        // All users should be able to get "groups" calendars properties
        // but result will only contains data for user groups.
        foreach ($users as $user_data) {
            $groups_expected_results = [
               [
                  'href'         => 'calendars/groups/',
                  'resourcetype' => 'd:collection',
               ],
            ];
            foreach ($user_data['groups'] as $group_id) {
                // Group principal should be listed in 'calendars/groups/' result
                $groups_expected_results[] = [
                   'href'         => 'calendars/groups/' . $group_id . '/',
                   'resourcetype' => 'd:collection',
                ];

                // Group calendar list properties should be accessible at 'calendars/groups/$group_id/'
                $dataset[] = [
                   'path' => 'calendars/groups/' . $group_id . '/',
                   'expected_results' => [
                      [
                         'href'         => 'calendars/groups/' . $group_id . '/',
                         'resourcetype' => 'd:collection',
                      ],
                      [
                         'href'         => 'calendars/groups/' . $group_id . '/calendar/',
                         'resourcetype' => ['d:collection', 'cal:calendar'],
                      ],
                   ],
                   'login' => $user_data['name'],
                   'pass' => $user_data['pass'],
                ];
            }
            $dataset[] = [
               'path' => 'calendars/groups/',
               'expected_results' => $groups_expected_results,
               'login' => $user_data['name'],
               'pass' => $user_data['pass'],
            ];
        }

        return $dataset;
    }

    /**
     * Test PROPFIND method on main endpoints (principals, calendars collections and calendars).
     * Tests only validates that response is ok and endpoints href and resourcetypes are correctly set.
     *
     * @dataProvider propfindMainEndpointsProvider
     */
    public function testPropfindOnMainEndpoints(string $path, array $expected_results, string $login, string $pass)
    {
        $this->login($login, $pass);

        $server = $this->getServerInstance('PROPFIND', $path);

        $this->validateThatAuthenticationIsRequired($server);

        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->validateResponseIsOk($response, 207, 'application/xml'); // 207 'Multi-Status'
        $xpath = $this->getXpathFromResponse($response);

        $this->integer((int)$xpath->evaluate('count(/d:multistatus/d:response)'))->isEqualTo(count($expected_results));

        $response_index = 1;
        foreach ($expected_results as $expected_result) {
            $result_path = '/d:multistatus/d:response[' . $response_index . ']';

            $this->string($xpath->evaluate('string(' . $result_path . '/d:href)'))->isEqualTo($expected_result['href']);

            $resourcetypes = $expected_result['resourcetype'];
            if (!is_array($resourcetypes)) {
                $resourcetypes = [$resourcetypes];
            }
            $this->integer(
                (int)$xpath->evaluate('count(' . $result_path . '/d:propstat/d:prop/d:resourcetype/child::node())')
            )->isEqualTo(count($resourcetypes));
            $child_index = 1;
            foreach ($resourcetypes as $resourcetype) {
                $this->integer(
                    (int)$xpath->evaluate('count(' . $result_path . '/d:propstat/d:prop/d:resourcetype/' . $resourcetype . ')')
                )->isEqualTo(1);
                $child_index++;
            }

            $response_index++;
        }
    }

    /**
     * Test PROPFIND method on calendar endpoints.
     * Tests validates that mandatory properties are correctly set.
     */
    public function testPropfindOnPrincipalCalendar()
    {

        $login = 'tech';
        $pass  = 'tech';
        $user  = getItemByTypeName('User', $login);

        $group = new \Group();
        $group_id = (int)$group->add([
           'name'    => 'Test group',
           'is_task' => 1,
        ]);
        $this->integer($group_id)->isGreaterThan(0);
        $group->getFromDB($group_id);

        $group_user = new \Group_User();
        $this->integer(
            (int)$group_user->add([
              'groups_id' => $group_id,
              'users_id'  => $user->fields['id'],
         ])
        )->isGreaterThan(0);

        $this->login($login, $pass);

        $calendars = [
           [
              'path' => 'calendars/users/' . $user->fields['name'] . '/calendar/',
              'name' => $user->getFriendlyName(),
           ],
           [
              'path' => 'calendars/groups/' . $group_id . '/calendar/',
              'name' => $group->getFriendlyName(),
           ],
        ];

        foreach ($calendars as $calendar) {
            $calendar_path = $calendar['path'];
            $calendar_name = $calendar['name'];

            $server = $this->getServerInstance('PROPFIND', $calendar_path);

            $this->validateThatAuthenticationIsRequired($server);

            $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

            $response = new \Sabre\HTTP\Response();
            $server->invokeMethod($server->httpRequest, $response, false);

            $this->validateResponseIsOk($response, 207, 'application/xml'); // 207 'Multi-Status'
            $xpath = $this->getXpathFromResponse($response);

            $result_path = '/d:multistatus/d:response[1]';

            $this->string($xpath->evaluate('string(' . $result_path . '/d:href)'))->isEqualTo($calendar_path);

            $this->integer(
                (int)$xpath->evaluate('count(' . $result_path . '/d:propstat/d:prop/d:resourcetype/child::node())')
            )->isEqualTo(2);
            $this->integer(
                (int)$xpath->evaluate('count(' . $result_path . '/d:propstat/d:prop/d:resourcetype/d:collection)')
            )->isEqualTo(1);
            $this->integer(
                (int)$xpath->evaluate('count(' . $result_path . '/d:propstat/d:prop/d:resourcetype/cal:calendar)')
            )->isEqualTo(1);

            $this->string(
                $xpath->evaluate('string(' . $result_path . '/d:propstat/d:prop/d:displayname)')
            )->isEqualTo($calendar_name);

            $this->integer(
                (int)$xpath->evaluate('count(' . $result_path . '/d:propstat/d:prop/cal:supported-calendar-component-set/cal:comp)')
            )->isEqualTo(3);
            $this->integer(
                (int)$xpath->evaluate('count(' . $result_path . '/d:propstat/d:prop/cal:supported-calendar-component-set/cal:comp[@name="VEVENT"])')
            )->isEqualTo(1);
            $this->integer(
                (int)$xpath->evaluate('count(' . $result_path . '/d:propstat/d:prop/cal:supported-calendar-component-set/cal:comp[@name="VJOURNAL"])')
            )->isEqualTo(1);
            $this->integer(
                (int)$xpath->evaluate('count(' . $result_path . '/d:propstat/d:prop/cal:supported-calendar-component-set/cal:comp[@name="VTODO"])')
            )->isEqualTo(1);
        }
    }

    /**
     * Test ACL on main objects.
     */
    public function testAcl()
    {

        $user  = getItemByTypeName('User', 'tech');

        $group = new \Group();
        $group_id = (int)$group->add([
           'name'    => 'Test group',
           'is_task' => 1,
        ]);
        $this->integer($group_id)->isGreaterThan(0);
        $group->getFromDB($group_id);

        $group_user = new \Group_User();
        $this->integer(
            (int)$group_user->add([
              'groups_id' => $group_id,
              'users_id'  => $user->fields['id'],
         ])
        )->isGreaterThan(0);

        $objects = [
           'principals/users/' . $user->fields['name'],
           'calendars/users/' . $user->fields['name'] . '/calendar/',
           'principals/groups/' . $group_id,
           'calendars/groups/' . $group_id . '/calendar/',
        ];

        $users_access = [
           'normal' => 'HTTP/1.1 403 Forbidden',
           'tech'   => 'HTTP/1.1 200 OK',
        ];

        foreach ($users_access as $username => $expected_status) {
            $this->login($username, $username);

            foreach ($objects as $path) {
                $server = $this->getServerInstance('PROPFIND', $path);

                $this->validateThatAuthenticationIsRequired($server);

                $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($username . ':' . $username));

                $response = new \Sabre\HTTP\Response();
                $server->invokeMethod($server->httpRequest, $response, false);
                $this->validateResponseIsOk($response, 207, 'application/xml'); // 207 'Multi-Status'

                $xpath = $this->getXpathFromResponse($response);
                $result_path = '/d:multistatus/d:response[1]';
                $this->string($xpath->evaluate('string(' . $result_path . '/d:propstat/d:status)'))->isEqualTo($expected_status);
            }
        }
    }

    /**
     * Test PROPFIND, GET, DELETE, PUT methods on calendar events.
     * Tests validates that mandatory properties are correctly set.
     */
    public function testMethodsCalendarEvents()
    {

        $login = TU_USER;
        $pass  = TU_PASS;
        $user  = getItemByTypeName('User', $login);

        $this->login($login, $pass);

        $event = new \PlanningExternalEvent();
        $event_id = (int)$event->add([
           'name'        => 'Test event created in GLPI',
           'entities_id' => $_SESSION['glpiactive_entity'],
           'plan'        => [
              'begin' => '2019-06-15 13:00:00',
              'end'   => '2019-06-15 13:45:00'
           ],
           'rrule'       => [
              'freq'      => 'weekly',
              'byweekday' => 'MO',
           ],
        ]);
        $this->integer($event_id)->isGreaterThan(0);
        $event->getFromDB($event_id);

        $event_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $event->fields['uuid'] . '.ics';

        // Validate PROPFIND method
        $server = $this->getServerInstance('PROPFIND', $event_path);

        $this->validateThatAuthenticationIsRequired($server);

        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->validateResponseIsOk($response, 207, 'application/xml'); // 207 'Multi-Status'
        $xpath = $this->getXpathFromResponse($response);

        $result_path = '/d:multistatus/d:response[1]';

        $this->string($xpath->evaluate('string(' . $result_path . '/d:href)'))->isEqualTo($event_path);
        $this->string(
            $xpath->evaluate('string(' . $result_path . '/d:propstat/d:prop/d:getlastmodified)')
        )->isEqualTo((new \DateTime($event->fields['date_mod']))->format('D, d M Y H:i:s \G\M\T'));
        $this->string(
            $xpath->evaluate('string(' . $result_path . '/d:propstat/d:prop/d:getcontenttype)')
        )->isEqualTo('text/calendar; charset=utf-8');

        // Validate GET method
        $server = $this->getServerInstance('GET', $event_path);

        $this->validateThatAuthenticationIsRequired($server);

        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->validateResponseIsOk($response, 200, 'text/calendar'); // 200 'OK'

        $vcalendar = \Sabre\VObject\Reader::read($response->getBodyAsString());
        $vcomp = $vcalendar->getBaseComponent();
        $this->object($vcomp)->isInstanceOf(\Sabre\VObject\Component\VEvent::class);
        $this->validateCommonVComponentProperties($vcomp, $event->fields);

        // Validate PUT method for update of an existing event
        $server = $this->getServerInstance('PUT', $event_path);
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VEVENT
UID:{$event->fields['uuid']}
SUMMARY:Test event updated from external source
DTSTART:20191010T113000Z
DTEND:20191010T123000Z
END:VEVENT
END:VCALENDAR
VCALENDAR
        );

        $this->validateThatAuthenticationIsRequired($server);

        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(204); // 204 'No Content'

        $updated_event = new \PlanningExternalEvent();
        $this->boolean($updated_event->getFromDBByCrit(['uuid' => $event->fields['uuid']]))->isTrue();
        $this->array($updated_event->fields)
           ->string['name']->isEqualTo('Test event updated from external source')
           ->string['begin']->isEqualTo('2019-10-10 11:30:00')
           ->string['rrule']->isEqualTo(''); // Validate that RRULE has been removed

        // Validate DELETE method
        $server = $this->getServerInstance('DELETE', $event_path);

        $this->validateThatAuthenticationIsRequired($server);

        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(204); // 204 'No Content'

        $this->boolean($event->getFromDB($event_id))->isFalse(); // Cannot read it anymore

        // Validate PUT method for creation of a new event
        $event_uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $event_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $event_uuid . '.ics';

        $server = $this->getServerInstance('PUT', $event_path);
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VEVENT
CREATED:20191001T082636Z
LAST-MODIFIED:20191001T082638Z
DTSTAMP:20191001T082638Z
UID:{$event_uuid}
SUMMARY:Test event created from external source
DTSTART:20191010T110000Z
DTEND:20191010T120000Z
END:VEVENT
END:VCALENDAR
VCALENDAR
        );

        $this->validateThatAuthenticationIsRequired($server);

        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(201); // 201 'Created'

        $new_event = new \PlanningExternalEvent();
        $this->boolean($new_event->getFromDBByCrit(['uuid' => $event_uuid]))->isTrue();
        $this->array($new_event->fields)
           ->string['uuid']->isEqualTo($event_uuid)
           ->string['name']->isEqualTo('Test event created from external source')
           ->string['begin']->isEqualTo('2019-10-10 11:00:00')
           ->string['end']->isEqualTo('2019-10-10 12:00:00');
    }

    /**
     * Test reading VEVENT object from PlanningExternalEvent class.
     * Tests validates that different properties are correctly handled.
     */
    public function testReadVEventFromPlanningExternalEvent()
    {

        $login = TU_USER;
        $pass  = TU_PASS;
        $user  = getItemByTypeName('User', $login);

        $this->login($login, $pass);

        $event = new \PlanningExternalEvent();
        $event_id = (int)$event->add([
           'name'        => 'Test event created in GLPI',
           'text'        => 'Description of the event.',
           'entities_id' => $_SESSION['glpiactive_entity'],
           'plan'        => [
              'begin' => '2019-06-15 13:00:00',
              'end'   => '2019-06-15 13:45:00'
           ],
           'rrule'       => [
              'freq'      => 'daily',
              'interval'  => 3,
              'byweekday' => 'MO,TU,WE,TH,FR',
           ],
        ]);
        $this->integer($event_id)->isGreaterThan(0);
        $event->getFromDB($event_id);

        $event_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $event->fields['uuid'] . '.ics';

        $server = $this->getServerInstance('GET', $event_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->validateResponseIsOk($response, 200, 'text/calendar'); // 200 'OK'

        $vcalendar = \Sabre\VObject\Reader::read($response->getBodyAsString());
        $vcomp = $vcalendar->getBaseComponent();
        $this->object($vcomp)->isInstanceOf(\Sabre\VObject\Component\VEvent::class);

        $this->validateCommonVComponentProperties($vcomp, $event->fields);

        $rrule_specs = current($vcomp->RRULE->getJsonValue()); // Get first array element which actually correspond to rrule specs
        $this->array($rrule_specs)->keys->isEqualTo(['freq', 'interval', 'byday']);
        $this->array($rrule_specs)
           ->string['freq']->isEqualTo('DAILY')
           ->string['interval']->isEqualTo('3')
           ->array['byday']->isEqualTo(['MO', 'TU', 'WE', 'TH', 'FR']);
    }

    /**
     * Test writing a VEVENT object to PlanningExternalEvent class.
     * Tests validates that different properties are correctly handled.
     */
    public function testWriteVEventToPlanningExternalEvent()
    {

        $login = TU_USER;
        $pass  = TU_PASS;
        $user  = getItemByTypeName('User', $login);

        $this->login($login, $pass);

        $event_uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $event_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $event_uuid . '.ics';

        $server = $this->getServerInstance('PUT', $event_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VEVENT
CREATED:20191001T082636Z
LAST-MODIFIED:20191001T082638Z
DTSTAMP:20191001T082638Z
UID:{$event_uuid}
SUMMARY:Test event created from external source
DESCRIPTION:Description of the event.
RRULE:FREQ=WEEKLY;INTERVAL=2;UNTIL=20191231T080000Z;BYDAY=MO,FR
DTSTART;TZID=Europe/Paris:20191101T090000
DTEND;TZID=Europe/Paris:20191101T120000
END:VEVENT
END:VCALENDAR
VCALENDAR
        );

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(201); // 201 'Created'

        $event = new \PlanningExternalEvent();
        $this->boolean($event->getFromDBByCrit(['uuid' => $event_uuid]))->isTrue();
        $this->array($event->fields)
           ->string['uuid']->isEqualTo($event_uuid)
           ->string['date_creation']->isEqualTo('2019-10-01 08:26:36')
           ->string['name']->isEqualTo('Test event created from external source')
           ->string['text']->isEqualTo('Description of the event.')
           ->integer['state']->isEqualTo(\Planning::INFO)
           ->string['begin']->isEqualTo('2019-11-01 08:00:00') // 1 hour offset between Europe/Paris and UTC
           ->string['end']->isEqualTo('2019-11-01 11:00:00') // 1 hour offset between Europe/Paris and UTC
           ->string['rrule'];
        $this->array(json_decode((string) $event->fields['rrule'], true))
           ->string['freq']->isEqualTo('WEEKLY')
           ->string['interval']->isEqualTo('2')
           ->string['until']->isEqualTo('2019-12-31 08:00:00')
           ->array['byday']->isEqualTo(['MO','FR']);
    }

    /**
     * Test reading VTODO object from PlanningExternalEvent class.
     * Tests validates that different properties are correctly handled.
     */
    public function testReadVTodoFromPlanningExternalEvent()
    {

        $login = TU_USER;
        $pass  = TU_PASS;
        $user  = getItemByTypeName('User', $login);

        $this->login($login, $pass);

        // Not planned task
        $event = new \PlanningExternalEvent();
        $event_id = (int)$event->add([
           'name'        => 'Task created in GLPI',
           'text'        => 'Description of the task.',
           'entities_id' => $_SESSION['glpiactive_entity'],
           'state'       => \Planning::DONE
        ]);
        $this->integer($event_id)->isGreaterThan(0);
        $event->getFromDB($event_id);

        $event_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $event->fields['uuid'] . '.ics';

        $server = $this->getServerInstance('GET', $event_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->validateResponseIsOk($response, 200, 'text/calendar'); // 200 'OK'

        $vcalendar = \Sabre\VObject\Reader::read($response->getBodyAsString());
        $vcomp = $vcalendar->getBaseComponent();
        $this->object($vcomp)->isInstanceOf(\Sabre\VObject\Component\VTodo::class);

        $this->validateCommonVComponentProperties($vcomp, $event->fields);

        $this->variable($vcomp->DTEND)->isNull(); // Be sure that VTODO does not contains a DTEND

        // Planned task
        $event = new \PlanningExternalEvent();
        $event_id = (int)$event->add([
           'name'        => 'Task created in GLPI',
           'text'        => 'Description of the task.',
           'entities_id' => $_SESSION['glpiactive_entity'],
           'plan'        => [
              'begin' => '2019-06-15 13:00:00',
              'end'   => '2019-06-15 13:45:00'
           ],
           'rrule'       => [
              'freq' => 'monthly',
           ],
           'state'       => \Planning::TODO
        ]);
        $this->integer($event_id)->isGreaterThan(0);
        $event->getFromDB($event_id);

        $event_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $event->fields['uuid'] . '.ics';

        $server = $this->getServerInstance('GET', $event_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->validateResponseIsOk($response, 200, 'text/calendar'); // 200 'OK'

        $vcalendar = \Sabre\VObject\Reader::read($response->getBodyAsString());
        $vcomp = $vcalendar->getBaseComponent();
        $this->object($vcomp)->isInstanceOf(\Sabre\VObject\Component\VTodo::class);

        $this->variable($vcomp->DTEND)->isNull(); // Be sure that VTODO does not contains a DTEND

        $rrule_specs = current($vcomp->RRULE->getJsonValue()); // Get first array element which actually correspond to rrule specs
        $this->array($rrule_specs)->keys->isEqualTo(['freq']);
        $this->array($rrule_specs)
           ->string['freq']->isEqualTo('MONTHLY');
    }

    /**
     * Test writing a VTODO object to PlanningExternalEvent class.
     * Tests validates that different properties are correctly handled.
     */
    public function testWriteVTodoToPlanningExternalEvent()
    {

        $login = TU_USER;
        $pass  = TU_PASS;
        $user  = getItemByTypeName('User', $login);

        $this->login($login, $pass);

        // Create planned task with TO DO state
        $event_uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $event_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $event_uuid . '.ics';

        $server = $this->getServerInstance('PUT', $event_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VTODO
CREATED:20191001T082636Z
LAST-MODIFIED:20191001T082638Z
DTSTAMP:20191001T082638Z
UID:{$event_uuid}
SUMMARY:Test task created from external source
DESCRIPTION:Description of the task.
STATUS:NEEDS-ACTION
DTSTART;TZID=Europe/Paris:20191101T090000
DUE;TZID=Europe/Paris:20191101T091500
END:VTODO
END:VCALENDAR
VCALENDAR
        );

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(201); // 201 'Created'

        $event = new \PlanningExternalEvent();
        $this->boolean($event->getFromDBByCrit(['uuid' => $event_uuid]))->isTrue();
        $this->array($event->fields)
           ->string['uuid']->isEqualTo($event_uuid)
           ->string['date_creation']->isEqualTo('2019-10-01 08:26:36')
           ->string['name']->isEqualTo('Test task created from external source')
           ->string['text']->isEqualTo('Description of the task.')
           ->integer['state']->isEqualTo(\Planning::TODO)
           ->string['begin']->isEqualTo('2019-11-01 08:00:00') // 1 hour offset between Europe/Paris and UTC
           ->string['end']->isEqualTo('2019-11-01 08:15:00') // 1 hour offset between Europe/Paris and UTC
           ->string['rrule']->isEqualTo('');

        // Create done and not planned task
        $event_uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $event_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $event_uuid . '.ics';

        $server = $this->getServerInstance('PUT', $event_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VTODO
CREATED:20191001T082636Z
LAST-MODIFIED:20191001T082638Z
DTSTAMP:20191001T082638Z
UID:{$event_uuid}
SUMMARY:Test task created from external source
DESCRIPTION:Description of the task.
STATUS:COMPLETED
END:VTODO
END:VCALENDAR
VCALENDAR
        );

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(201); // 201 'Created'

        $event = new \PlanningExternalEvent();
        $this->boolean($event->getFromDBByCrit(['uuid' => $event_uuid]))->isTrue();
        $this->array($event->fields)
           ->string['uuid']->isEqualTo($event_uuid)
           ->string['date_creation']->isEqualTo('2019-10-01 08:26:36')
           ->string['name']->isEqualTo('Test task created from external source')
           ->string['text']->isEqualTo('Description of the task.')
           ->integer['state']->isEqualTo(\Planning::DONE)
           ->variable['begin']->isEqualTo(null)
           ->variable['end']->isEqualTo(null)
           ->string['rrule']->isEqualTo('');
    }

    /**
     * Test reading VJOURNAL object from PlanningExternalEvent class.
     * Tests validates that different properties are correctly handled.
     */
    public function testReadVJournalFromPlanningExternalEvent()
    {

        $login = TU_USER;
        $pass  = TU_PASS;
        $user  = getItemByTypeName('User', $login);

        $this->login($login, $pass);

        $event = new \PlanningExternalEvent();
        $event_id = (int)$event->add([
           'name'        => 'Note created in GLPI',
           'text'        => 'Description of the note.',
           'entities_id' => $_SESSION['glpiactive_entity'],
           'state'       => \Planning::INFO
        ]);
        $this->integer($event_id)->isGreaterThan(0);
        $event->getFromDB($event_id);

        $event_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $event->fields['uuid'] . '.ics';

        $server = $this->getServerInstance('GET', $event_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->validateResponseIsOk($response, 200, 'text/calendar'); // 200 'OK'

        $vcalendar = \Sabre\VObject\Reader::read($response->getBodyAsString());
        $vcomp = $vcalendar->getBaseComponent();
        $this->object($vcomp)->isInstanceOf(\Sabre\VObject\Component\VJournal::class);

        $this->validateCommonVComponentProperties($vcomp, $event->fields);

        // Be sure that VJOURNAL does not contains plan information
        $this->variable($vcomp->DTSTART)->isNull();
        $this->variable($vcomp->DTEND)->isNull();
        $this->variable($vcomp->DUE)->isNull();
    }

    /**
     * Test writing a VJOURNAL object to PlanningExternalEvent class.
     * Tests validates that different properties are correctly handled.
     */
    public function testWriteVJournalToPlanningExternalEvent()
    {

        $login = TU_USER;
        $pass  = TU_PASS;
        $user  = getItemByTypeName('User', $login);

        $this->login($login, $pass);

        $event_uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $event_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $event_uuid . '.ics';

        $server = $this->getServerInstance('PUT', $event_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VJOURNAL
CREATED:20191001T082636Z
LAST-MODIFIED:20191001T082638Z
DTSTAMP:20191001T082638Z
UID:{$event_uuid}
SUMMARY:Note created from external source
DESCRIPTION:Description of the note.
END:VJOURNAL
END:VCALENDAR
VCALENDAR
        );

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(201); // 201 'Created'

        $event = new \PlanningExternalEvent();
        $this->boolean($event->getFromDBByCrit(['uuid' => $event_uuid]))->isTrue();
        $this->array($event->fields)
           ->string['uuid']->isEqualTo($event_uuid)
           ->string['date_creation']->isEqualTo('2019-10-01 08:26:36')
           ->string['name']->isEqualTo('Note created from external source')
           ->string['text']->isEqualTo('Description of the note.')
           ->integer['state']->isEqualTo(\Planning::INFO)
           ->variable['begin']->isEqualTo(null)
           ->variable['end']->isEqualTo(null)
           ->string['rrule']->isEqualTo('');
    }

    /**
     * Test reading and writing VTODO object from Reminder class.
     * Tests validates that different properties are correctly handled.
     */
    public function testReadWriteVTodoFromReminder()
    {

        $login = TU_USER;
        $pass  = TU_PASS;
        $user  = getItemByTypeName('User', $login);

        $this->login($login, $pass);

        $reminder = new \Reminder();
        $reminder_id = (int)$reminder->add([
           'name'        => 'Test reminder created in GLPI',
           'text'        => 'Description of the reminder.',
           'entities_id' => $_SESSION['glpiactive_entity'],
        ]);
        $this->integer($reminder_id)->isGreaterThan(0);
        $reminder->getFromDB($reminder_id);

        $creation_date = $reminder->fields['date_creation'];

        $reminder_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $reminder->fields['uuid'] . '.ics';

        // Test reading VTODO object
        $server = $this->getServerInstance('GET', $reminder_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->validateResponseIsOk($response, 200, 'text/calendar'); // 200 'OK'

        $vcalendar = \Sabre\VObject\Reader::read($response->getBodyAsString());
        $vcomp = $vcalendar->getBaseComponent();
        $this->object($vcomp)->isInstanceOf(\Sabre\VObject\Component\VJournal::class);

        $this->validateCommonVComponentProperties($vcomp, $reminder->fields);

        // Test updating VTODO object
        $server = $this->getServerInstance('PUT', $reminder_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VJOURNAL
CREATED:20191001T082636Z
LAST-MODIFIED:20191001T082638Z
DTSTAMP:20191001T082638Z
UID:{$reminder->fields['uuid']}
SUMMARY:Test reminder updated from external source
DESCRIPTION:Updated description.
END:VJOURNAL
END:VCALENDAR
VCALENDAR
        );

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(204); // 204 'No Content'

        $this->boolean($reminder->getFromDBByCrit(['uuid' => $reminder->fields['uuid']]))->isTrue();
        $this->array($reminder->fields)
           ->string['uuid']->isEqualTo($reminder->fields['uuid'])
           ->string['date_creation']->isEqualTo($creation_date) // be sure that creation date is not overrided
           ->string['name']->isEqualTo('Test reminder updated from external source')
           ->string['text']->isEqualTo('Updated description.')
           ->integer['state']->isEqualTo(\Planning::INFO);
    }

    /**
     * Test reading and writing VJOURNAL object from Reminder class.
     * Tests validates that different properties are correctly handled.
     */
    public function testReadWriteVJournalFromReminder()
    {

        $login = TU_USER;
        $pass  = TU_PASS;
        $user  = getItemByTypeName('User', $login);

        $this->login($login, $pass);

        $reminder = new \Reminder();
        $reminder_id = (int)$reminder->add([
           'name'        => 'Test reminder created in GLPI',
           'text'        => 'Description of the reminder.',
           'entities_id' => $_SESSION['glpiactive_entity'],
           'plan'        => [
              'begin' => '2019-06-15 13:00:00',
              'end'   => '2019-06-15 13:45:00'
           ],
           'state'       => \Planning::TODO,
        ]);
        $this->integer($reminder_id)->isGreaterThan(0);
        $reminder->getFromDB($reminder_id);

        $creation_date = $reminder->fields['date_creation'];

        $reminder_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $reminder->fields['uuid'] . '.ics';

        // Test reading VTODO object
        $server = $this->getServerInstance('GET', $reminder_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->validateResponseIsOk($response, 200, 'text/calendar'); // 200 'OK'

        $vcalendar = \Sabre\VObject\Reader::read($response->getBodyAsString());
        $vcomp = $vcalendar->getBaseComponent();
        $this->object($vcomp)->isInstanceOf(\Sabre\VObject\Component\VTodo::class);

        $this->validateCommonVComponentProperties($vcomp, $reminder->fields);

        // Test updating VTODO object
        $server = $this->getServerInstance('PUT', $reminder_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VTODO
CREATED:20191001T082636Z
LAST-MODIFIED:20191001T082638Z
DTSTAMP:20191001T082638Z
UID:{$reminder->fields['uuid']}
SUMMARY:Test reminder updated from external source
DESCRIPTION:Updated description.
STATUS:COMPLETED
DTSTART;TZID=Europe/Paris:20191101T090000
DUE;TZID=Europe/Paris:20191101T091500
END:VTODO
END:VCALENDAR
VCALENDAR
        );

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(204); // 204 'No Content'

        $this->boolean($reminder->getFromDBByCrit(['uuid' => $reminder->fields['uuid']]))->isTrue();
        $this->array($reminder->fields)
           ->string['uuid']->isEqualTo($reminder->fields['uuid'])
           ->string['date_creation']->isEqualTo($creation_date) // be sure that creation date is not overrided
           ->string['name']->isEqualTo('Test reminder updated from external source')
           ->string['text']->isEqualTo('Updated description.')
           ->integer['state']->isEqualTo(\Planning::DONE)
           ->string['begin']->isEqualTo('2019-11-01 08:00:00') // 1 hour offset between Europe/Paris and UTC
           ->string['end']->isEqualTo('2019-11-01 08:15:00'); // 1 hour offset between Europe/Paris and UTC
    }

    /**
     * Test reading and writing VTODO object from TicketTask class.
     * Tests validates that different properties are correctly handled.
     */
    public function testReadWriteVTodoFromTicketTask()
    {

        $login = TU_USER;
        $pass  = TU_PASS;
        $user  = getItemByTypeName('User', $login);

        $this->login($login, $pass);

        $ticket = new \Ticket();
        $ticket_id = (int)$ticket->add([
           'name'               => 'Test ticket',
           'content'            => 'Ticket content.',
           'users_id_recipient' => $user->fields['id'],
           'entities_id'        => $_SESSION['glpiactive_entity'],
        ]);
        $this->integer($ticket_id)->isGreaterThan(0);

        $ticket_task = new \TicketTask();
        $ticket_task_id = (int)$ticket_task->add([
           'tickets_id'    => $ticket_id,
           'content'       => 'Description of the task.',
           'users_id_tech' => $user->fields['id'],
        ]);
        $this->integer($ticket_task_id)->isGreaterThan(0);
        $ticket_task->getFromDB($ticket_task_id);

        $creation_date = $ticket_task->fields['date_creation'];

        $ticket_task_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $ticket_task->fields['uuid'] . '.ics';

        // Test reading VTODO object
        $server = $this->getServerInstance('GET', $ticket_task_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->validateResponseIsOk($response, 200, 'text/calendar'); // 200 'OK'

        $vcalendar = \Sabre\VObject\Reader::read($response->getBodyAsString());
        $vcomp = $vcalendar->getBaseComponent();
        $this->object($vcomp)->isInstanceOf(\Sabre\VObject\Component\VTodo::class);

        $this->validateCommonVComponentProperties($vcomp, $ticket_task->fields);

        // Test updating VTODO object (without plan information)
        $server = $this->getServerInstance('PUT', $ticket_task_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VTODO
CREATED:20191001T082636Z
LAST-MODIFIED:20191001T082638Z
DTSTAMP:20191001T082638Z
UID:{$ticket_task->fields['uuid']}
SUMMARY:Test ticket
DESCRIPTION:Updated description.
STATUS:NEEDS-ACTION
END:VTODO
END:VCALENDAR
VCALENDAR
        );

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(204); // 204 'No Content'

        $this->boolean($ticket_task->getFromDBByCrit(['uuid' => $ticket_task->fields['uuid']]))->isTrue();
        $this->array($ticket_task->fields)
           ->string['uuid']->isEqualTo($ticket_task->fields['uuid'])
           ->string['date_creation']->isEqualTo($creation_date) // be sure that creation date is not overrided
           ->string['content']->isEqualTo('Updated description.')
           ->integer['state']->isEqualTo(\Planning::TODO);

        // Test updating VTODO object (with plan information)
        $server = $this->getServerInstance('PUT', $ticket_task_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VTODO
CREATED:20191001T082636Z
LAST-MODIFIED:20191001T082638Z
DTSTAMP:20191001T082638Z
UID:{$ticket_task->fields['uuid']}
SUMMARY:Test ticket
DESCRIPTION:Updated description.
STATUS:COMPLETED
DTSTART;TZID=Europe/Paris:20191101T090000
DUE;TZID=Europe/Paris:20191101T091500
END:VTODO
END:VCALENDAR
VCALENDAR
        );

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(204); // 204 'No Content'

        $this->boolean($ticket_task->getFromDBByCrit(['uuid' => $ticket_task->fields['uuid']]))->isTrue();
        $this->array($ticket_task->fields)
           ->string['uuid']->isEqualTo($ticket_task->fields['uuid'])
           ->string['date_creation']->isEqualTo($creation_date) // be sure that creation date is not overrided
           ->string['content']->isEqualTo('Updated description.')
           ->integer['state']->isEqualTo(\Planning::DONE)
           ->string['begin']->isEqualTo('2019-11-01 08:00:00') // 1 hour offset between Europe/Paris and UTC
           ->string['end']->isEqualTo('2019-11-01 08:15:00'); // 1 hour offset between Europe/Paris and UTC
    }

    /**
     * Test reading and writing VTODO object from ProjectTask class.
     * Tests validates that different properties are correctly handled.
     */
    public function testReadWriteVTodoFromProjectTask()
    {

        $login = TU_USER;
        $pass  = TU_PASS;
        $user  = getItemByTypeName('User', $login);

        $this->login($login, $pass);

        $project = new \Project();
        $project_id = (int)$project->add([
           'name'        => 'Test project',
           'content'     => 'Project content.',
           'entities_id' => $_SESSION['glpiactive_entity'],
        ]);
        $this->integer($project_id)->isGreaterThan(0);

        $project_task = new \ProjectTask();
        $project_task_id = (int)$project_task->add([
           'name'        => 'Test task created in GLPI',
           'content'     => 'Description of the task.',
           'projects_id' => $project_id,
           'entities_id' => $_SESSION['glpiactive_entity'],
        ]);
        $this->integer($project_task_id)->isGreaterThan(0);
        $project_task->getFromDB($project_task_id);

        $project_task_team = new \ProjectTaskTeam();
        $project_task_team_id = (int)$project_task_team->add([
           'projecttasks_id' => $project_task_id,
           'itemtype'        => 'User',
           'items_id'        => $user->fields['id'],
        ]);
        $this->integer($project_task_team_id)->isGreaterThan(0);

        $creation_date = $project_task->fields['date'];

        $project_task_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $project_task->fields['uuid'] . '.ics';

        // Test reading VTODO object
        $server = $this->getServerInstance('GET', $project_task_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->validateResponseIsOk($response, 200, 'text/calendar'); // 200 'OK'

        $vcalendar = \Sabre\VObject\Reader::read($response->getBodyAsString());
        $vcomp = $vcalendar->getBaseComponent();
        $this->object($vcomp)->isInstanceOf(\Sabre\VObject\Component\VTodo::class);

        $this->validateCommonVComponentProperties($vcomp, $project_task->fields);
        $this->object($vcomp->{'PERCENT-COMPLETE'})->isInstanceOf(\Sabre\VObject\Property\IntegerValue::class);
        $this->integer($vcomp->{'PERCENT-COMPLETE'}->getValue())->isEqualTo((int)$project_task->fields['percent_done']);

        // Test updating VTODO object (without plan information)
        $server = $this->getServerInstance('PUT', $project_task_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VTODO
CREATED:20191001T082636Z
LAST-MODIFIED:20191001T082638Z
DTSTAMP:20191001T082638Z
UID:{$project_task->fields['uuid']}
SUMMARY:Test ticket
DESCRIPTION:Updated description.
STATUS:NEEDS-ACTION
PERCENT-COMPLETE:35
END:VTODO
END:VCALENDAR
VCALENDAR
        );

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(204); // 204 'No Content'

        $this->boolean($project_task->getFromDBByCrit(['uuid' => $project_task->fields['uuid']]))->isTrue();
        $this->array($project_task->fields)
           ->string['uuid']->isEqualTo($project_task->fields['uuid'])
           ->string['date']->isEqualTo($creation_date) // be sure that creation date is not overrided
           ->string['content']->isEqualTo('Updated description.')
           ->integer['percent_done']->isEqualTo(35);

        // Test updating VTODO object (with plan information)
        $server = $this->getServerInstance('PUT', $project_task_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VTODO
CREATED:20191001T082636Z
LAST-MODIFIED:20191001T082638Z
DTSTAMP:20191001T082638Z
UID:{$project_task->fields['uuid']}
SUMMARY:Test ticket
DESCRIPTION:Updated description.
STATUS:COMPLETED
DTSTART;TZID=Europe/Paris:20191101T090000
DUE;TZID=Europe/Paris:20191101T091500
END:VTODO
END:VCALENDAR
VCALENDAR
        );

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(204); // 204 'No Content'

        $this->boolean($project_task->getFromDBByCrit(['uuid' => $project_task->fields['uuid']]))->isTrue();
        $this->array($project_task->fields)
           ->string['uuid']->isEqualTo($project_task->fields['uuid'])
           ->string['date']->isEqualTo($creation_date) // be sure that creation date is not overrided
           ->string['content']->isEqualTo('Updated description.')
           ->integer['percent_done']->isEqualTo(100)
           ->string['plan_start_date']->isEqualTo('2019-11-01 08:00:00') // 1 hour offset between Europe/Paris and UTC
           ->string['plan_end_date']->isEqualTo('2019-11-01 08:15:00'); // 1 hour offset between Europe/Paris and UTC
    }

    /**
     * Test that not handled properties are persistent when reading a previously saved object.
     */
    public function testPersistenceOfNotHandledProps()
    {

        $login = TU_USER;
        $pass  = TU_PASS;
        $user  = getItemByTypeName('User', $login);

        $this->login($login, $pass);

        $event_uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $event_path = 'calendars/users/' . $user->fields['name'] . '/calendar/' . $event_uuid . '.ics';

        // Store a new object
        $server = $this->getServerInstance('PUT', $event_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));
        $server->httpRequest->setBody(
            <<<VCALENDAR
BEGIN:VCALENDAR
PRODID:-//glpi-project.org/NONSGML GLPI Test suite//EN
VERSION:2.0
BEGIN:VEVENT
CREATED:20191001T082636Z
LAST-MODIFIED:20191001T082638Z
DTSTAMP:20191001T082638Z
UID:{$event_uuid}
SUMMARY:Test event created from external source
DESCRIPTION:Description of the event.
RRULE:FREQ=WEEKLY;INTERVAL=2;UNTIL=20191231T080000Z;BYDAY=MO,FR
DTSTART;TZID=Europe/Paris:20191101T090000
DTEND;TZID=Europe/Paris:20191101T120000
CATEGORIES:First category
CATEGORIES:Another cat
LOCATION:Here
END:VEVENT
END:VCALENDAR
VCALENDAR
        );

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->integer($response->getStatus())->isEqualTo(201); // 201 'Created'

        // Read created object
        $server = $this->getServerInstance('GET', $event_path);
        $server->httpRequest->addHeader('Authorization', 'Basic ' . base64_encode($login . ':' . $pass));

        $response = new \Sabre\HTTP\Response();
        $server->invokeMethod($server->httpRequest, $response, false);

        $this->validateResponseIsOk($response, 200, 'text/calendar'); // 200 'OK'

        $vcalendar = \Sabre\VObject\Reader::read($response->getBodyAsString());
        $vcomp = $vcalendar->getBaseComponent();
        $this->object($vcomp)->isInstanceOf(\Sabre\VObject\Component\VEvent::class);
        $this->array($categories = $vcomp->select('CATEGORIES'))->hasSize(2);
        $this->array($categories)->object[0]->isInstanceOf(\Sabre\VObject\Property\Text::class);
        $this->string($categories[0]->getValue())->isEqualTo('First category');
        $this->array($categories)->object[1]->isInstanceOf(\Sabre\VObject\Property\Text::class);
        $this->string($categories[1]->getValue())->isEqualTo('Another cat');
        $this->object($vcomp->LOCATION)->isInstanceOf(\Sabre\VObject\Property\FlatText::class);
        $this->string($vcomp->LOCATION->getValue())->isEqualTo('Here');
    }

    /**
     * Validate that method invocation on server will result in a
     * NotAuthenticated exception.
     *
     * @param \Glpi\CalDAV\Server $server
     */
    private function validateThatAuthenticationIsRequired(\Glpi\CalDAV\Server $server)
    {
        $this->exception(
            function () use ($server) {
                $response = new \Sabre\HTTP\Response();
                $server->invokeMethod($server->httpRequest, $response, false);
            }
        )->isInstanceOf(\Sabre\DAV\Exception\NotAuthenticated::class);
    }

    /**
     * Validate that response is OK.
     *
     * @param \Sabre\HTTP\Response $response
     * @param integer              $status
     * @param string|null          $content_type
     */
    private function validateResponseIsOk(\Sabre\HTTP\Response $response, int $status, string $content_type)
    {
        $this->integer($response->getStatus())->isEqualTo($status);
        $this->string($response->getHeader('Content-Type'))->isEqualTo($content_type . '; charset=utf-8');
        $response_body = $response->getBodyAsString();
        $this->string($response_body)->isNotEmpty();
        // If initial response was a stream, reading it changed stream pointer and make it hard to read again.
        // Setting body as text makes it infinitely readable.
        $response->setBody($response_body);
    }

    /**
     * Get a server instance.
     *
     * @param string $http_method
     * @param string $path
     *
     * @return \Glpi\CalDAV\Server
     */
    private function getServerInstance(string $http_method, string $path): \Glpi\CalDAV\Server
    {
        $base_url = '/caldav.php';

        $server = new \Glpi\CalDAV\Server();
        $server->httpRequest->setBaseUrl($base_url);
        $server->httpRequest->setMethod($http_method);
        $server->httpRequest->setUrl($base_url . $path);

        return $server;
    }

    /**
     * Get a XPath object from response.
     *
     * @param \Sabre\HTTP\Response $response
     *
     * @return \DOMXPath
     */
    private function getXpathFromResponse(\Sabre\HTTP\Response $response): \DOMXPath
    {
        $xml = new \DOMDocument();
        $this->boolean($xml->loadXML($response->getBodyAsString()))->isTrue();
        return new \DOMXPath($xml);
    }

    /**
     * Validate common VComponent properies based on object fields.
     *
     * @param \Sabre\VObject\Component $vcomp
     * @param array $fields
     */
    private function validateCommonVComponentProperties(\Sabre\VObject\Component $vcomp, array $fields)
    {

        $this->object($vcomp->UID)->isInstanceOf(\Sabre\VObject\Property\FlatText::class);
        $this->string($vcomp->UID->getValue())->isEqualTo($fields['uuid']);

        if (array_key_exists('name', $fields)) {
            $this->object($vcomp->SUMMARY)->isInstanceOf(\Sabre\VObject\Property\FlatText::class);
            $this->string($vcomp->SUMMARY->getValue())->isEqualTo($fields['name']);
        }

        $content = array_key_exists('text', $fields) ? $fields['text'] : $fields['content'];
        $this->object($vcomp->DESCRIPTION)->isInstanceOf(\Sabre\VObject\Property\FlatText::class);
        $this->string($vcomp->DESCRIPTION->getValue())->isEqualTo($content);

        $creation_date = array_key_exists('date_creation', $fields) ? $fields['date_creation'] : $fields['date'];
        $this->object($vcomp->CREATED)->isInstanceOf(\Sabre\VObject\Property\ICalendar\DateTime::class);
        $this->string($vcomp->CREATED->getDateTime()->format('Y-m-d H:i:s'))->isEqualTo($creation_date);

        $this->object($vcomp->DTSTAMP)->isInstanceOf(\Sabre\VObject\Property\ICalendar\DateTime::class);
        $this->string($vcomp->DTSTAMP->getDateTime()->format('Y-m-d H:i:s'))->isEqualTo($fields['date_mod']);
        $this->object($vcomp->{'LAST-MODIFIED'})->isInstanceOf(\Sabre\VObject\Property\ICalendar\DateTime::class);
        $this->string($vcomp->{'LAST-MODIFIED'}->getDateTime()->format('Y-m-d H:i:s'))->isEqualTo($fields['date_mod']);

        if (!empty($fields['begin'])) {
            $this->object($vcomp->DTSTART)->isInstanceOf(\Sabre\VObject\Property\ICalendar\DateTime::class);
            $this->string($vcomp->DTSTART->getDateTime()->format('Y-m-d H:i:s'))->isEqualTo($fields['begin']);
        } else {
            $this->variable($vcomp->DTSTART)->isNull();
        }
        $end_field = $vcomp instanceof \Sabre\VObject\Component\VEvent ? 'DTEND' : 'DUE';
        if (!empty($fields['end'])) {
            $this->object($vcomp->$end_field)->isInstanceOf(\Sabre\VObject\Property\ICalendar\DateTime::class);
            $this->string($vcomp->$end_field->getDateTime()->format('Y-m-d H:i:s'))->isEqualTo($fields['end']);
        } else {
            $this->variable($vcomp->$end_field)->isNull();
        }

        if (!empty($fields['rrule'])) {
            $this->object($vcomp->RRULE)->isInstanceOf(\Sabre\VObject\Property\ICalendar\Recur::class);
            $this->array($vcomp->RRULE->getJsonValue())->hasSize(1);
        } else {
            $this->variable($vcomp->RRULE)->isNull();
        }
    }
}

/** Trace existing virtual boundaries without changing parser or URI semantics. */
class PrincipalCallbackProbe extends Principal
{
    public array $events = [];
    public ?Closure $onGroupUri = null;
    public ?Closure $onType = null;
    public ?array $parentIds = null;

    protected function getPrincipalItemtypeFromUri($uri)
    {
        $this->events[] = 'type';
        ($this->onType)?->__invoke();
        return parent::getPrincipalItemtypeFromUri($uri);
    }

    protected function getGroupIdFromPrincipalUri($uri)
    {
        $this->events[] = 'group-id';
        $id = parent::getGroupIdFromPrincipalUri($uri);
        return $this->parentIds ?? $id;
    }

    protected function getGroupPrincipalUri($id)
    {
        $this->events[] = 'group-uri';
        ($this->onGroupUri)?->__invoke($id);
        return parent::getGroupPrincipalUri($id);
    }

    protected function getUserPrincipalUri($name)
    {
        $this->events[] = 'user-uri';
        return parent::getUserPrincipalUri($name);
    }
}
