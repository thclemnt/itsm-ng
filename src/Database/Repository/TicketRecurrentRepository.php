<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\TicketRecurrent;

final class TicketRecurrentRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Select only; callers retain ticket creation, history and scheduling hooks. */
    public function due(?DateTimeImmutable $now = null): array
    {
        $query = $this->em->createQueryBuilder()->select('r')->from(TicketRecurrent::class, 'r')
            ->where('r.is_active = :active AND r.next_creation_date < :now AND (r.end_date IS NULL OR r.end_date > :now)')
            ->setParameter('active', true, Types::BOOLEAN)
            ->setParameter('now', $now ?? new DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE)
            ->orderBy('r.next_creation_date')->addOrderBy('r.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
        }
        return $rows;
    }
}
