<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Dashboard;

final class DashboardRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Prefer personal/profile-specific overrides, without selecting another profile's dashboard. */
    public function forUser(int $user, int $profile): ?int
    {
        if ($user <= 0) {
            return null;
        }
        $row = $this->em->createQueryBuilder()->select('d.id')->from(Dashboard::class, 'd')
            ->where('(IDENTITY(d.owner) IS NULL OR IDENTITY(d.owner) = :user) AND (IDENTITY(d.profile) IS NULL OR IDENTITY(d.profile) = :profile)')
            ->setParameter('user', $user, Types::INTEGER)->setParameter('profile', $profile, Types::INTEGER)
            ->orderBy('CASE WHEN IDENTITY(d.owner) IS NULL THEN 1 ELSE 0 END')->addOrderBy('CASE WHEN IDENTITY(d.profile) IS NULL THEN 1 ELSE 0 END')->addOrderBy('d.id')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        return $row === null ? null : (int)$row['id'];
    }
}
