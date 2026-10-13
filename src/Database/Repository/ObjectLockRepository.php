<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\ObjectLock;

final class ObjectLockRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Selection only; the model still owns unlocking, audit history and notifications. */
    public function expired(DateTimeImmutable $before): array
    {
        $query = $this->em->createQueryBuilder()->select('r')->from(ObjectLock::class, 'r')
            ->where('r.date_mod < :before')->setParameter('before', $before, Types::DATETIMETZ_IMMUTABLE)
            ->orderBy('r.date_mod')->addOrderBy('r.id');
        return (new RecordRepository($this->em))->toRows($query->getQuery()->toIterable());
    }
}
