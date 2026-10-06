<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\Group;
use itsmng\Database\Entity\GroupMembership;
use itsmng\Database\Entity\ProfileUser;
use itsmng\Database\Entity\User;
use itsmng\Database\RecordCriteria;

/** Membership projections with grant existence checks before pagination. */
final class GroupMembershipRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function groupsWithMembers(): array
    {
        return array_map('intval', array_column($this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(m.groups) AS group_id')->from(GroupMembership::class, 'm')
            ->orderBy('group_id')->getQuery()->getScalarResult(), 'group_id'));
    }

    /** Login groups are scoped by the Group owner, not by other users' profile grants. */
    public function sessionGroupIds(int $user, array $groupScope): array
    {
        $query = $this->em->createQueryBuilder()->select('IDENTITY(r.groups) AS group_id')
            ->from(GroupMembership::class, 'r')->join('r.groups', 'g')
            ->where('IDENTITY(r.users) = :user')->setParameter('user', $user, Types::INTEGER);
        $compiler = (new RecordCriteria($query, $this->em->getClassMetadata(GroupMembership::class), false))
            ->withJoinedMetadata($this->em->getClassMetadata(Group::class), 'g');
        $query->andWhere($compiler->where($groupScope))->orderBy('r.id');
        return array_map('intval', array_column($query->getQuery()->getScalarResult(), 'group_id'));
    }

    public function groupsForUser(int $user, array $criteria): array
    {
        $query = $this->em->createQueryBuilder()->select('r', 'g')->from(GroupMembership::class, 'r')->join('r.groups', 'g');
        $compiler = (new RecordCriteria($query, $this->em->getClassMetadata(GroupMembership::class), false))
            ->withJoinedMetadata($this->em->getClassMetadata(Group::class), 'g');
        $query->where($compiler->where(['glpi_groups_users.users_id' => $user] + $criteria));
        $this->orderField($query, 'g.name', 'ASC', 'unnamed');
        return $this->relatedRows($query, true);
    }

    public function usersForGroup(int $group, array $criteria): array
    {
        $query = $this->em->createQueryBuilder()->select('r', 'u')->from(GroupMembership::class, 'r')->join('r.users', 'u');
        $compiler = (new RecordCriteria($query, $this->em->getClassMetadata(GroupMembership::class), false))
            ->withJoinedMetadata($this->em->getClassMetadata(User::class), 'u');
        $query->where($compiler->where(['glpi_groups_users.groups_id' => $group] + $criteria));
        $this->orderField($query, 'u.name', 'ASC', 'unnamed');
        return $this->relatedRows($query, false);
    }

    /** Zero limit is unbounded; link fields serve only the hook-free member renderer. */
    public function members(array $groups, array $scope, string $criterion = '', int $offset = 0, int $limit = 0, string $sort = 'group', string $direction = 'ASC', bool $tree = false, bool $withLinkFields = false): array
    {
        $query = $this->visibleMembers($groups, $scope);
        if (in_array($criterion, ['is_manager', 'is_userdelegate'], true)) {
            $query->andWhere('m.' . $criterion . ' = :yes')->setParameter('yes', true, Types::BOOLEAN);
        }
        $total = (int)(clone $query)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
        $query->select(
            'u.id AS id',
            'm.id AS linkid',
            'IDENTITY(m.groups) AS groups_id',
            'm.is_dynamic AS is_dynamic',
            'm.is_manager AS is_manager',
            'm.is_userdelegate AS is_userdelegate'
        );
        if ($withLinkFields) {
            $query->addSelect(
                'u.name AS user_name',
                'u.realname AS user_realname',
                'u.firstname AS user_firstname',
                'g.name AS group_name',
                'g.completename AS group_completename',
                'g.comment AS group_comment',
                'IDENTITY(g.entities) AS group_entities_id',
                'g.is_recursive AS group_is_recursive'
            );
        }
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $field = match ($sort) {
            'parent' => 'g.completename', 'dynamic' => 'm.is_dynamic', 'manager' => 'm.is_manager', 'delegatee' => 'm.is_userdelegate',
            default => $tree ? 'g.completename' : null,
        };
        if ($field !== null) {
            $this->orderField($query, $field, $direction, 'primary_sort');
        }
        foreach (['realname', 'firstname', 'name'] as $name) {
            $this->orderField($query, 'u.' . $name, $field === null ? $direction : 'ASC', 'sort_' . $name);
        }
        $query->addOrderBy('m.id');
        if ($limit > 0) {
            $query->setFirstResult(max(0, $offset))->setMaxResults($limit);
        }
        $rows = $query->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            foreach (['is_dynamic', 'is_manager', 'is_userdelegate'] as $flag) {
                $row[$flag] = (int)$row[$flag];
            }
        }
        return ['total' => $total, 'rows' => $rows];
    }

    public function directUserIds(int $group, array $scope): array
    {
        return array_map('intval', array_column($this->visibleMembers([$group], $scope)->select('u.id AS id')
            ->orderBy('m.id')->getQuery()->getScalarResult(), 'id'));
    }

    private function visibleMembers(array $groups, array $scope): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from(GroupMembership::class, 'm')->join('m.users', 'u')->join('m.groups', 'g')
            ->where('IDENTITY(m.groups) IN (:groups)')->setParameter('groups', array_values(array_map('intval', $groups)) ?: [-1]);
        $grant = $this->em->createQueryBuilder()->select('r.id')->from(ProfileUser::class, 'r')->where('r.users = u');
        $grant->andWhere((new RecordCriteria($grant, $this->em->getClassMetadata(ProfileUser::class), false))->where($scope));
        // Users with no authorization remain visible, as in the former LEFT JOIN.
        $query->andWhere('(NOT EXISTS (SELECT absent.id FROM ' . ProfileUser::class . ' absent WHERE absent.users = u) OR EXISTS (' . $grant->getDQL() . '))');
        foreach ($grant->getParameters() as $parameter) {
            $query->setParameter($parameter->getName(), $parameter->getValue(), $parameter->getType());
        }
        return $query;
    }

    private function relatedRows(QueryBuilder $query, bool $groups): array
    {
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->addOrderBy('r.id')->getQuery()->toIterable() as $membership) {
            $rows[] = array_merge($records->toRow($groups ? $membership->groups : $membership->users), [
                'IDD' => $membership->id, 'linkid' => $membership->id, 'is_dynamic' => (int)$membership->is_dynamic,
                'is_manager' => (int)$membership->is_manager, 'is_userdelegate' => (int)$membership->is_userdelegate,
            ]);
            $this->em->detach($membership);
        }
        return $rows;
    }

    private function orderField(QueryBuilder $query, string $field, string $direction, string $alias): void
    {
        $query->addSelect('CASE WHEN ' . $field . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN ' . $alias)
            ->addOrderBy($alias, $direction)->addOrderBy($field, $direction);
    }
}
