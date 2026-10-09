<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Alert;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

final class CertificateRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Strict calendar-day cutoff, excluding certificates already alerted at end of life. */
    public function expiring(int $entity, int $days, ?DateTimeImmutable $today = null): array
    {
        $cutoff = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0)->modify(sprintf('%+d days', $days));
        $query = $this->em->createQueryBuilder()->select('c')->from(Entity\Certificate::class, 'c')
            ->leftJoin(Entity\Alert::class, 'a', 'WITH', 'a.certificate = c AND a.type = :end')
            ->setParameter('end', Alert::END, Types::INTEGER)
            ->where('IDENTITY(c.entities) = :entity AND c.is_deleted = :false AND c.is_template = :false')
            ->setParameter('entity', $entity, Types::INTEGER)->setParameter('false', false, Types::BOOLEAN)
            ->andWhere('a.id IS NULL AND c.date_expiration < :cutoff')->setParameter('cutoff', $cutoff, Types::DATE_IMMUTABLE)
            ->orderBy('c.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
        }
        return $rows;
    }
}
