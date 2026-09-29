<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\User;
use itsmng\Database\Entity\UserEmail;

/** Address lookup and default selection through the mapped user association. */
final class UserEmailRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function all(int $user): array
    {
        $rows = $this->em->createQueryBuilder()->select('e.email')->from(UserEmail::class, 'e')
            ->where('IDENTITY(e.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('e.id')->getQuery()->getScalarResult();
        return array_column($rows, 'email');
    }

    public function preferred(int $user): ?array
    {
        return $this->em->createQueryBuilder()->select('e.id, e.email')->from(UserEmail::class, 'e')
            ->where('IDENTITY(e.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('e.is_default', 'DESC')->addOrderBy('e.id')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function contains(int $user, string $email): bool
    {
        return $this->em->createQueryBuilder()->select('e.id')->from(UserEmail::class, 'e')
            ->where('IDENTITY(e.users) = :user AND e.email = :email')->setParameter('user', $user, Types::INTEGER)
            ->setParameter('email', $email)->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    /** Choose an existing address of this user, or their preferred survivor after deletion. */
    public function selectDefault(int $user, ?int $address = null): bool
    {
        return $this->em->getConnection()->transactional(function () use ($user, $address): bool {
            if ($user <= 0 || $this->em->find(User::class, $user, LockMode::PESSIMISTIC_WRITE) === null) {
                return false;
            }
            if ($address === null) {
                $address = $this->preferred($user)['id'] ?? null;
            }
            if ($address === null) {
                return false;
            }
            $selected = $this->em->createQueryBuilder()->select('e.id')->from(UserEmail::class, 'e')
                ->where('e.id = :id AND IDENTITY(e.users) = :user')->setParameter('id', $address, Types::INTEGER)
                ->setParameter('user', $user, Types::INTEGER)->getQuery()->getOneOrNullResult();
            if ($selected === null) {
                return false;
            }
            // Set both sides explicitly: a previous concurrent selection may have
            // cleared the selected address after its model was initially loaded.
            $query = $this->em->createQueryBuilder()->update(UserEmail::class, 'e')->set('e.is_default', ':default')
                ->where('IDENTITY(e.users) = :user')->setParameter('user', $user, Types::INTEGER);
            (clone $query)->andWhere('e.id <> :id')->setParameter('id', $address, Types::INTEGER)
                ->setParameter('default', false, Types::BOOLEAN)->getQuery()->execute();
            $query->andWhere('e.id = :id')->setParameter('id', $address, Types::INTEGER)
                ->setParameter('default', true, Types::BOOLEAN)->getQuery()->execute();
            return true;
        });
    }
}
