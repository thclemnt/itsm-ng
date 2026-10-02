<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\ProfileRight;
use itsmng\Database\Entity\ProfileUser;
use itsmng\Database\RecordCriteria;

/** Authorization grants; recursive tree expansion remains with the entity service. */
final class ProfileUserRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function scopes(int $user, ?int $profile = null, ?string $right = null, int $mask = 0): array
    {
        $query = $this->em->createQueryBuilder()->select('DISTINCT IDENTITY(r.entities) AS entities_id', 'r.is_recursive AS is_recursive')
            ->from(ProfileUser::class, 'r')->where('IDENTITY(r.users) = :user')->setParameter('user', $user, Types::INTEGER);
        if ($profile !== null) {
            $query->andWhere('IDENTITY(r.profiles) = :profile')->setParameter('profile', $profile, Types::INTEGER);
        }
        if ($right !== null) {
            $query->join(ProfileRight::class, 'permission', 'WITH', 'permission.profiles = r.profiles')
                ->andWhere('permission.name = :right AND BIT_AND(permission.rights, :mask) <> 0')
                ->setParameter('right', $right)->setParameter('mask', $mask, Types::INTEGER);
        }
        return $query->getQuery()->getScalarResult();
    }

    public function usersInEntity(int $entity): array
    {
        $query = $this->em->createQueryBuilder()->select('r', 'u', 'p')->from(ProfileUser::class, 'r')
            ->join('r.users', 'u')->join('r.profiles', 'p')->where('IDENTITY(r.entities) = :entity AND u.is_deleted = :deleted')
            ->setParameter('entity', $entity, Types::INTEGER)->setParameter('deleted', false, Types::BOOLEAN)->orderBy('p.id');
        $this->order($query, ['u.name', 'u.realname', 'u.firstname']);
        return $this->rows($query, false);
    }

    public function countUsersInEntity(int $entity): int
    {
        return (int)$this->em->createQueryBuilder()->select('COUNT(r.id)')->from(ProfileUser::class, 'r')->join('r.users', 'u')
            ->where('IDENTITY(r.entities) = :entity AND u.is_deleted = :deleted')->setParameter('entity', $entity, Types::INTEGER)
            ->setParameter('deleted', false, Types::BOOLEAN)->getQuery()->getSingleScalarResult();
    }

    public function usersWithProfile(int $profile, array $scope): array
    {
        $query = $this->em->createQueryBuilder()->select('r', 'u', 'e')->from(ProfileUser::class, 'r')
            ->join('r.users', 'u')->join('r.entities', 'e')->where('IDENTITY(r.profiles) = :profile AND u.is_deleted = :deleted')
            ->setParameter('profile', $profile, Types::INTEGER)->setParameter('deleted', false, Types::BOOLEAN);
        $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata(ProfileUser::class), false))->where($scope));
        $this->order($query, ['e.completename', 'u.name']);
        return $this->rows($query, true);
    }

    private function rows(QueryBuilder $query, bool $entity): array
    {
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->addOrderBy('r.id')->getQuery()->toIterable() as $grant) {
            $extra = ['linkid' => $grant->id, 'is_recursive' => (int)$grant->is_recursive, 'is_dynamic' => (int)$grant->is_dynamic];
            if ($entity) {
                $extra += ['entity' => $grant->entities->id, 'entityname' => $grant->entities->completename];
            } else {
                $extra += ['pid' => $grant->profiles->id, 'pname' => $grant->profiles->name];
            }
            $rows[] = array_merge($records->toRow($grant->users), $extra);
            $this->em->detach($grant);
        }
        return $rows;
    }

    /** Retain MySQL's NULL-first name ordering on both providers. */
    private function order(QueryBuilder $query, array $fields): void
    {
        foreach ($fields as $index => $field) {
            $query->addSelect('CASE WHEN ' . $field . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN absent' . $index)
                ->addOrderBy('absent' . $index)->addOrderBy($field);
        }
    }
}
