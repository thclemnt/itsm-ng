<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\Ticket;
use itsmng\Database\Entity\TicketSatisfaction;

/** Candidate reads only: automatic actions retain the ticket model's lifecycle. */
final class TicketAutomaticActionRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function closeCandidates(int $entity, int $status, int $days, ?DateTimeImmutable $calendarCutoff = null, ?DateTimeImmutable $now = null): array
    {
        $query = $this->inEntity($entity)->andWhere('t.status = :status AND t.is_deleted = :no')
            ->setParameter('status', $status, Types::INTEGER)->setParameter('no', false, Types::BOOLEAN);
        if ($days > 0) {
            if ($calendarCutoff !== null) {
                $query->andWhere('t.solvedate <= :cutoff')->setParameter('cutoff', $calendarCutoff, Types::DATETIMETZ_IMMUTABLE);
            } else {
                $query->andWhere("DATE_ADD(t.solvedate, :days, 'DAY') < " . $this->time($query, $now))->setParameter('days', $days, Types::INTEGER);
            }
        }
        return $this->identifiers($query);
    }

    /** Closed tickets include soft-deleted rows, as in the existing purge action. */
    public function purgeCandidates(int $entity, array $statuses, int $days, ?DateTimeImmutable $now = null): array
    {
        if (!$statuses) {
            return [];
        }
        $query = $this->inEntity($entity)->andWhere('t.status IN (:statuses)')->setParameter('statuses', $statuses);
        if ($days > 0) {
            $query->andWhere("DATE_ADD(t.closedate, :days, 'DAY') < " . $this->time($query, $now))->setParameter('days', $days, Types::INTEGER);
        }
        return $this->identifiers($query);
    }

    public function overdue(int $entity, array $statuses, int $days, ?DateTimeImmutable $now = null): array
    {
        if (!$statuses) {
            return [];
        }
        $query = $this->inEntity($entity)->select('t')->andWhere('t.status IN (:statuses) AND t.is_deleted = :no AND t.closedate IS NULL')
            ->setParameter('statuses', $statuses)->setParameter('no', false, Types::BOOLEAN);
        $query->andWhere("DATE_ADD(t.date, :days, 'DAY') < " . $this->time($query, $now))->setParameter('days', $days, Types::INTEGER)
            ->orderBy('t.id');
        return (new RecordRepository($this->em))->toRows($query->getQuery()->toIterable());
    }

    /** Inherited selection watermark and the entity's own duration gate are distinct. */
    public function surveyCandidates(int $entity, int $status, ?DateTimeImmutable $after, int $days, int $duration, ?DateTimeImmutable $now = null): array
    {
        if ($after === null) {
            return [];
        }
        $query = $this->inEntity($entity)->select('t.id, t.closedate, IDENTITY(t.entities) AS entities_id')
            ->join('t.entities', 'e')->andWhere('t.status = :status AND t.is_deleted = :no AND t.closedate > :after')
            ->setParameter('status', $status, Types::INTEGER)->setParameter('no', false, Types::BOOLEAN)
            ->setParameter('after', $after, Types::DATETIMETZ_IMMUTABLE);
        $query->andWhere("DATE_ADD(t.closedate, :days, 'DAY') <= " . $this->time($query, $now))
            ->andWhere("DATE_ADD(e.max_closedate, :duration, 'DAY') <= " . $this->time($query, $now))
            ->setParameter('days', $days, Types::INTEGER)->setParameter('duration', $duration, Types::INTEGER)
            ->andWhere('NOT EXISTS (SELECT s.id FROM ' . TicketSatisfaction::class . ' s WHERE s.tickets = t)')
            ->orderBy('t.closedate')->addOrderBy('t.id');
        $rows = $query->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['entities_id'] = (int)$row['entities_id'];
            $row['closedate'] = $row['closedate'] instanceof DateTimeInterface ? $row['closedate']->format('Y-m-d H:i:s')
                : (new DateTimeImmutable($row['closedate']))->format('Y-m-d H:i:s');
        }
        return $rows;
    }

    private function inEntity(int $entity): QueryBuilder
    {
        return $this->em->createQueryBuilder()->from(Ticket::class, 't')->where('IDENTITY(t.entities) = :entity')
            ->setParameter('entity', $entity, Types::BIGINT);
    }

    private function time(QueryBuilder $query, ?DateTimeImmutable $now): string
    {
        if ($now === null) {
            return 'CURRENT_TIMESTAMP()';
        }
        $query->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE);
        return ':now';
    }

    /** Snapshot identifiers before public model hooks change candidate rows. */
    private function identifiers(QueryBuilder $query): array
    {
        return array_map('intval', array_column($query->select('t.id')->orderBy('t.id')->getQuery()->getScalarResult(), 'id'));
    }
}
