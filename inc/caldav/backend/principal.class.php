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

namespace Glpi\CalDAV\Backend;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use CommonDBTM;
use DBAdapter;
use DbUtils;
use Doctrine\ORM\EntityManager;
use Glpi\CalDAV\Node\Property;
use Glpi\CalDAV\Traits\CalDAVPrincipalsTrait;
use Glpi\CalDAV\Traits\CalDAVUriUtilTrait;
use Group;
use Group_User;
use itsmng\Database\Entity\Group as MappedGroup;
use itsmng\Database\Entity\GroupMembership;
use itsmng\Database\Entity\User as MappedUser;
use itsmng\Database\EntityRegistry;
use itsmng\Database\EntityRestriction;
use itsmng\Database\Orm;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\Repository\PrincipalGroupRepository;
use QuerySubQuery;
use Sabre\DAV\Exception\NotImplemented;
use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\AbstractBackend;
use User;
use UserEmail;

/**
 * Principal backend for CalDAV server.
 *
 * @see http://sabre.io/dav/principals/
 *
 * @since 9.5.0
 */
class Principal extends AbstractBackend
{
    use CalDAVPrincipalsTrait;
    use CalDAVUriUtilTrait;

    public const PRINCIPALS_ROOT = 'principals';
    public const PREFIX_GROUPS   = self::PRINCIPALS_ROOT . '/groups';
    public const PREFIX_USERS    = self::PRINCIPALS_ROOT . '/users';

    public function getPrincipalsByPrefix($prefixPath)
    {

        $principals = [];

        switch ($prefixPath) {
            case self::PREFIX_GROUPS:
                $groups_iterator = $this->getVisibleGroupsIterator();
                foreach ($groups_iterator as $group_fields) {
                    $principals[] = $this->getPrincipalFromGroupFields($group_fields);
                }
                break;
            case self::PREFIX_USERS:
                $users_iterator = $this->getVisibleUsersIterator();
                foreach ($users_iterator as $user_fields) {
                    $principals[] = $this->getPrincipalFromUserFields($user_fields);
                }
                break;
        }

        usort(
            $principals,
            function ($p1, $p2) {
                return $p1['id'] - $p2['id'];
            }
        );

        return $principals;
    }

    public function getPrincipalByPath($path)
    {

        $item = $this->getPrincipalItemFromUri($path);

        if (null === $item) {
            return;
        }

        return $this->getPrincipalFromItem($item);
    }

    public function updatePrincipal($path, PropPatch $propPatch)
    {
        throw new NotImplemented('Principal update is not implemented');
    }

    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
    {

        throw new NotImplemented('Principal search is not implemented');
    }

    public function findByUri($uri, $principalPrefix)
    {
        throw new NotImplemented('Principal findByUri is not implemented');
    }

    public function getGroupMemberSet($path)
    {

        global $DB;

        $principal_itemtype = $this->getPrincipalItemtypeFromUri($path);
        $group_id           = $this->getGroupIdFromPrincipalUri($path);

        if (Group::class !== $principal_itemtype) {
            return [];
        }

        $members_uris = [];

        $groups_database = $DB;
        $groups_query = [
            'FROM' => Group::getTable(),
            'WHERE' => [
                'is_task' => 1,
                'groups_id' => $group_id,
            ] + ($scope = (new DbUtils())->getEntityRestriction(
                Group::getTable(),
                'entities_id',
                $_SESSION['glpiactiveentities'],
                true
            ))->wrappedCriteria(),
        ];
        $groups_iterator = $this->projectGroupRelations($groups_database, $groups_query, $scope, $group_id)
            ?? $groups_database->request($groups_query);
        foreach ($groups_iterator as $group_fields) {
            $members_uris[] = $this->getGroupPrincipalUri($group_fields['id']);
        }

        $users_database = $DB;
        $users_query = [
            'SELECT' => [User::getTableField('name')],
            'FROM' => User::getTable(),
            'INNER JOIN' => [
                Group_User::getTable() => [
                    'ON' => [
                        User::getTable() => 'id',
                        Group_User::getTable() => 'users_id',
                    ],
                ],
            ],
            'WHERE' => [
                Group_User::getTableField('groups_id') => $group_id,
            ],
        ];
        $users_iterator = $this->projectGroupMembers($users_database, $users_query, $group_id)
            ?? $users_database->request($users_query);
        foreach ($users_iterator as $user_fields) {
            $members_uris[] = $this->getUserPrincipalUri($user_fields['name']);
        }

        return $members_uris;
    }

    public function getGroupMembership($path)
    {

        global $DB;

        $groups_query = [
           'SELECT'     => [Group::getTableField('id')],
           'FROM'       => Group::getTable(),
           'INNER JOIN' => [],
           'WHERE'      => [
              'is_task' => 1,
           ] + ($scope = (new DbUtils())->getEntityRestriction(
               Group::getTable(),
               'entities_id',
               $_SESSION['glpiactiveentities'],
               true
           ))->wrappedCriteria(),
        ];

        $principal_itemtype = $this->getPrincipalItemtypeFromUri($path);
        switch ($principal_itemtype) {
            case Group::class:
                $groups_query['WHERE']['groups_id'] = $selection = $this->getGroupIdFromPrincipalUri($path);
                break;
            case User::class:
                $groups_query['INNER JOIN'][$membership_table = Group_User::getTable()] = [
                   'ON' => [
                      Group::getTable()       => 'id',
                      Group_User::getTable()  => 'groups_id',
                      [
                         'AND' => [
                            Group_User::getTableField('users_id') => new QuerySubQuery(
                                [
                                  'SELECT' => 'id',
                                  'FROM'   => $user_table = User::getTable(),
                                  'WHERE'  => ['name' => $selection = $this->getUsernameFromPrincipalUri($path)],
                                ]
                            ),
                         ],
                      ],
                   ]
                ];
                break;
            default:
                return []; // No groups if principal is not a user or a group
                break;
        }

        $groups_database = $DB;
        $groups_iterator = $this->projectGroupRelations(
            $groups_database,
            $groups_query,
            $scope,
            $selection,
            $user_table ?? null,
            $membership_table ?? null,
        ) ?? $groups_database->request($groups_query);

        $groups_uris = [];
        foreach ($groups_iterator as $group_fields) {
            $groups_uris[] = $this->getGroupPrincipalUri($group_fields['id']);
        }
        return $groups_uris;
    }

    /** Only fixed, property-owned routes enter the completed domain projection. */
    private function projectGroupRelations(
        DBAdapter $database,
        array $query,
        EntityRestriction $scope,
        mixed $selection,
        ?string $userTable = null,
        ?string $membershipTable = null,
    ): ?array {
        $tables = EntityRegistry::tables();
        if (($tables[$query['FROM']] ?? null) !== MappedGroup::class
            || $scope->table !== $query['FROM'] || !$scope->hasEntityMembership
            || $scope->field !== 'entities_id'
            || (isset($query['SELECT']) && $query['SELECT'] !== [$query['FROM'] . '.id'])) {
            return null;
        }
        if ($userTable !== null) {
            if (($tables[$userTable] ?? null) !== MappedUser::class
                || ($tables[$membershipTable ?? ''] ?? null) !== GroupMembership::class
                || ($query['INNER JOIN'][$membershipTable]['ON'][$query['FROM']] ?? null) !== 'id'
                || ($query['INNER JOIN'][$membershipTable]['ON'][$membershipTable] ?? null) !== 'groups_id'
                || (!is_string($selection) && $selection !== null)) {
                return null;
            }
        } elseif (!$this->isPrincipalReference($selection)) {
            return null;
        }
        $selection = $this->principalNullSelection($selection);
        $connection = $database->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($database, $connection);
        return Orm::withReadConnection($connection, static function (?EntityManager $manager) use ($connection, $selection, $scope, $userTable): array {
            $repository = new PrincipalGroupRepository($connection, $manager);
            return $userTable === null ? $repository->taskChildIds($selection, $scope)
                : $repository->taskMembershipIdsForUsername($selection, $scope);
        });
    }

    private function projectGroupMembers(DBAdapter $database, array $query, mixed $group): ?array
    {
        $tables = EntityRegistry::tables();
        $user = $query['FROM'];
        $membership = array_key_first($query['INNER JOIN']);
        if (($tables[$user] ?? null) !== MappedUser::class
            || ($tables[$membership] ?? null) !== GroupMembership::class
            || $query['SELECT'] !== [$user . '.name']
            || $query['INNER JOIN'][$membership]['ON'] !== [$user => 'id', $membership => 'users_id']
            || array_keys($query['WHERE']) !== [$membership . '.groups_id']
            || !$this->isPrincipalReference($group)) {
            return null;
        }
        $group = $this->principalNullSelection($group);
        $connection = $database->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($database, $connection);
        return Orm::withReadConnection($connection, static fn (?EntityManager $manager): array =>
            (new PrincipalGroupRepository($connection, $manager))->directMemberNames($group));
    }

    private function isPrincipalReference(mixed $value): bool
    {
        return $value === null || is_int($value)
            || (is_string($value) && (ctype_digit($value) || strtolower($value) === 'null'));
    }

    private function principalNullSelection(mixed $value): mixed
    {
        return is_string($value) && strtolower($value) === 'null' ? null : $value;
    }

    public function setGroupMemberSet($path, array $members)
    {
        throw new NotImplemented('Group member set update is not implemented');
    }

    /**
     * Get principal object based on item.
     *
     * @param \CommonDBTM $item
     *
     * @return null|array
     */
    private function getPrincipalFromItem(CommonDBTM $item)
    {

        $principal = null;

        switch (get_class($item)) {
            case Group::class:
                $principal = $this->getPrincipalFromGroupFields($item->fields);
                break;
            case User::class:
                $principal = $this->getPrincipalFromUserFields($item->fields);
                break;
        }

        return $principal;
    }

    /**
     * Get principal object based on user fields.
     *
     * @param array $user_fields
     *
     * @return array
     */
    private function getPrincipalFromUserFields(array $user_fields)
    {
        return [
           'id'                    => $user_fields['id'],
           'uri'                   => $this->getUserPrincipalUri($user_fields['name']),
           Property::USERNAME      => $user_fields['name'],
           Property::DISPLAY_NAME  => formatUserName(
               $user_fields['id'],
               $user_fields['name'],
               $user_fields['realname'],
               $user_fields['firstname']
           ),
           Property::PRIMARY_EMAIL => UserEmail::getDefaultForUser($user_fields['id']),
           Property::CAL_USER_TYPE => 'INDIVIDUAL',
        ];
    }

    /**
     * Get principal object based on user fields.
     *
     * @param array $group_fields
     *
     * @return array
     */
    private function getPrincipalFromGroupFields(array $group_fields)
    {
        return [
           'id'                    => $group_fields['id'],
           'uri'                   => $this->getGroupPrincipalUri($group_fields['id']),
           Property::DISPLAY_NAME  => $group_fields['name'],
           Property::CAL_USER_TYPE => 'GROUP',
        ];
    }
}
