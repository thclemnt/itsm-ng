<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\ProfileUser;
use itsmng\Database\Entity\User;
use itsmng\Database\Entity\UserEmail;
use itsmng\Database\RecordCriteria;

final class UserRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function profiles(int $user): array
    {
        $rows = $this->em->createQueryBuilder()->select('DISTINCT p.id, p.name')->from(ProfileUser::class, 'a')
            ->join('a.profiles', 'p')->where('IDENTITY(a.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('p.id')->getQuery()->getScalarResult();
        return array_column($rows, 'name', 'id');
    }

    /** Replacements are preferences only; this query never grants a profile. */
    public function defaultProfileReplacements(int $profile, int $replacement): array
    {
        return $this->em->createQueryBuilder()->select('DISTINCT u.id, IDENTITY(a.profiles) AS profiles_id')->from(User::class, 'u')
            ->leftJoin(ProfileUser::class, 'a', 'WITH', 'a.users = u AND IDENTITY(a.profiles) = :replacement')
            ->where('IDENTITY(u.profiles) = :profile')->setParameter('profile', $profile, Types::INTEGER)
            ->setParameter('replacement', $replacement === $profile ? 0 : $replacement, Types::INTEGER)
            ->orderBy('u.id')->getQuery()->getScalarResult();
    }

    public function emails(int $user): array
    {
        return $this->em->createQueryBuilder()->select('e.id, e.is_default, e.email')->from(UserEmail::class, 'e')
            ->where('IDENTITY(e.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('e.id')->getQuery()->getScalarResult();
    }

    public function preferredByEmail(string $email): ?int
    {
        $row = $this->em->createQueryBuilder()->select('u.id')->from(UserEmail::class, 'e')->join('e.users', 'u')
            ->where('LOWER(e.email) = LOWER(:email)')->setParameter('email', $email)
            ->orderBy('u.is_active', 'DESC')->addOrderBy('u.is_deleted')->addOrderBy('u.id')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        return $row === null ? null : (int)$row['id'];
    }

    /** Fetch at most two IDs so ambiguity never resolves to an arbitrary account. */
    public function uniqueId(string $field, mixed $value, bool $legacyValues = false): ?int
    {
        $query = $this->em->createQueryBuilder()->select('r.id')->from(User::class, 'r');
        $criteria = new RecordCriteria($query, $this->em->getClassMetadata(User::class), $legacyValues);
        $query->where($criteria->where([$field => $value]))->setMaxResults(2);
        $rows = $query->getQuery()->getScalarResult();
        return count($rows) === 1 ? (int)$rows[0]['id'] : null;
    }
}
