<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\AuthMail;

/** Mail authentication configuration; authentication itself stays in the mail service. */
final class MailAuthenticationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function activeCount(): int
    {
        return (int)$this->em->createQueryBuilder()->select('COUNT(m.id)')->from(AuthMail::class, 'm')
            ->where('m.is_active = :yes')->setParameter('yes', true, Types::BOOLEAN)->getQuery()->getSingleScalarResult();
    }

    public function servers(bool $activeOnly = false): array
    {
        $query = $this->em->createQueryBuilder()->select('m')->from(AuthMail::class, 'm');
        if ($activeOnly) {
            $query->where('m.is_active = :yes')->setParameter('yes', true, Types::BOOLEAN)->orderBy('m.name');
        }
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->addOrderBy('m.id')->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return $rows;
    }
}
