<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\Group;
use itsmng\Database\Entity\GroupMembership;
use itsmng\Database\Entity\Profile;
use itsmng\Database\Entity\ProfileRight;
use itsmng\Database\Entity\ProfileUser;
use itsmng\Database\Entity\User;
use itsmng\Database\Entity\UserEmail;
use itsmng\Database\RecordCriteria;
use itsmng\Database\RowIterator;

/** Permission-filtered dropdowns, bounded in the database before record hydration. */
final class UserSelectionRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function byEmail(string $email, array $conditions, bool $activeOnly = false): array
    {
        $query = $this->em->createQueryBuilder()->select('r.id')->from(User::class, 'r')
            ->leftJoin(UserEmail::class, 'e', 'WITH', 'e.users = r');
        $criteria = (new RecordCriteria($query, $this->em->getClassMetadata(User::class)))
            ->withJoinedMetadata($this->em->getClassMetadata(UserEmail::class), 'e');
        $query->where($criteria->where(['glpi_useremails.email' => $email] + $conditions))->orderBy('r.id')->addOrderBy('e.id');
        if ($activeOnly) {
            $this->restrictActive($query);
        }
        // The former iterator keyed rows by user ID: repeated emails on one
        // account collapse, while different accounts remain ambiguous.
        return array_values(array_unique(array_map('intval', array_column($query->getQuery()->getScalarResult(), 'id'))));
    }

    public function delegatedGroups(int $user, array $scope): array
    {
        $query = $this->em->createQueryBuilder()->select('DISTINCT r.id')->from(Group::class, 'r')
            ->join(GroupMembership::class, 'm', 'WITH', 'm.groups = r')
            ->where('IDENTITY(m.users) = :user AND m.is_userdelegate = :yes')
            ->setParameter('user', $user, Types::INTEGER)->setParameter('yes', true, Types::BOOLEAN);
        $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata(Group::class)))->where($scope));
        $ids = array_map('intval', array_column($query->orderBy('r.id')->getQuery()->getScalarResult(), 'id'));
        return array_combine($ids, $ids);
    }

    public function groupMembers(array $groups, int $exclude): array
    {
        if (!$groups) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()->select('DISTINCT IDENTITY(m.users) AS id')
            ->from(GroupMembership::class, 'm')->where('IDENTITY(m.groups) IN (:groups) AND IDENTITY(m.users) <> :exclude')
            ->setParameter('groups', array_values(array_map('intval', $groups)))->setParameter('exclude', $exclude, Types::INTEGER)
            ->orderBy('id')->getQuery()->getScalarResult();
        $ids = array_map('intval', array_column($rows, 'id'));
        return array_combine($ids, $ids);
    }

    public function search(
        array $permissions,
        bool $count,
        array $used,
        ?string $pattern,
        bool $includeInactive,
        bool $firstnameFirst,
        int $start,
        int $limit,
        bool $search = false,
        bool $namesOnly = false,
    ): RowIterator {
        $matching = $this->em->createQueryBuilder()->select('r.id')->from(User::class, 'r');
        $compiler = new RecordCriteria($matching, $this->em->getClassMetadata(User::class));
        $needsRights = $this->referencesTable($permissions, 'glpi_profilerights');
        $needsProfile = $needsRights || $this->referencesTable($permissions, 'glpi_profiles');
        if ($needsProfile || $this->referencesTable($permissions, 'glpi_profiles_users')) {
            $matching->leftJoin(ProfileUser::class, 'a', 'WITH', 'a.users = r');
            $compiler->withJoinedMetadata($this->em->getClassMetadata(ProfileUser::class), 'a');
        }
        if ($needsProfile) {
            $matching->leftJoin('a.profiles', 'p');
            $compiler->withJoinedMetadata($this->em->getClassMetadata(Profile::class), 'p');
        }
        if ($needsRights) {
            $matching->leftJoin(ProfileRight::class, 'pr', 'WITH', 'pr.profiles = p');
            $compiler->withJoinedMetadata($this->em->getClassMetadata(ProfileRight::class), 'pr');
        }
        if (!$count && $search) {
            $matching->leftJoin(UserEmail::class, 'e', 'WITH', 'e.users = r');
        }
        $matching->where($compiler->where($permissions));
        if (!$includeInactive) {
            $this->restrictActive($matching);
        }
        if ($used) {
            $matching->andWhere('r.id NOT IN (:used)')->setParameter('used', array_values(array_map('intval', $used)));
        }
        // Historically the count reports all eligible users before text filtering.
        if (!$count && $search) {
            $name = 'CASE WHEN r.firstname IS NULL OR r.realname IS NULL THEN NULL ELSE '
                . ($firstnameFirst ? "CONCAT(r.firstname, ' ', r.realname)" : "CONCAT(r.realname, ' ', r.firstname)") . ' END';
            $fields = ['r.name', 'r.realname', 'r.firstname', 'r.phone', 'e.email', $name];
            if ($pattern === null) {
                $matching->andWhere('1 = 0');
            } else {
                $matching->andWhere('(' . implode(' OR ', array_map(static fn ($field) => 'LOWER(' . $field . ') LIKE LOWER(:search)', $fields)) . ')')
                    ->setParameter('search', $pattern, Types::STRING);
            }
        }
        // Identity subquery prevents email/profile fan-out without comparing JSON values.
        $selection = $count ? 'COUNT(u.id) AS CPT' : ($namesOnly ? 'u.id, u.name, u.realname, u.firstname' : 'u');
        $query = $this->em->createQueryBuilder()->select($selection)->from(User::class, 'u')
            ->where('u.id IN (' . $matching->getDQL() . ')');
        foreach ($matching->getParameters() as $parameter) {
            $query->setParameter($parameter->getName(), $parameter->getValue(), $parameter->getType());
        }
        if ($count) {
            return new RowIterator($query->getQuery()->getScalarResult());
        }
        foreach ($firstnameFirst ? ['firstname', 'realname', 'name'] : ['realname', 'firstname', 'name'] as $field) {
            $query->addSelect('CASE WHEN u.' . $field . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN null_' . $field)
                ->addOrderBy('null_' . $field)->addOrderBy('u.' . $field);
        }
        $query->addOrderBy('u.id');
        if ($limit > 0) {
            $query->setMaxResults($limit)->setFirstResult(max(0, $start));
        }
        if ($namesOnly) {
            return new RowIterator($query->getQuery()->getScalarResult());
        }
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return new RowIterator($rows);
    }

    /** Add only joins actually needed by the structured permission predicate. */
    private function referencesTable(array $criteria, string $table): bool
    {
        foreach ($criteria as $column => $value) {
            if (is_string($column) && str_starts_with($column, $table . '.')) {
                return true;
            }
            if ((is_int($column) || in_array($column, ['AND', 'OR', 'NOT'], true))
                && is_array($value) && $this->referencesTable($value, $table)) {
                return true;
            }
        }
        return false;
    }

    private function restrictActive(QueryBuilder $query): void
    {
        $query->andWhere('r.is_active = :active AND r.is_deleted = :deleted')
            ->andWhere('(r.begin_date IS NULL OR r.begin_date < CURRENT_TIMESTAMP())')
            ->andWhere('(r.end_date IS NULL OR r.end_date > CURRENT_TIMESTAMP())')
            ->setParameter('active', true, Types::BOOLEAN)->setParameter('deleted', false, Types::BOOLEAN);
    }
}
