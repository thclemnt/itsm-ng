<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Database\EntityRestriction;
use itsmng\Database\RecordCriteria;

/** Fixed Change/Problem-to-Ticket endpoints; application callbacks own item visibility. */
final class ITILTicketLinkRepository
{
    private const TICKET_FIELDS = [
        'l.id AS linkid', 'r.id', 'r.name', 'r.status', 'r.priority',
        'IDENTITY(r.entities) AS entities_id', 'IDENTITY(r.itilcategories) AS itilcategories_id',
        "TEMPORAL_TEXT(r.date, 'datetime') AS date", "TEMPORAL_TEXT(r.date_mod, 'datetime') AS date_mod",
        "TEMPORAL_TEXT(r.closedate, 'datetime') AS closedate", "TEMPORAL_TEXT(r.solvedate, 'datetime') AS solvedate",
        "TEMPORAL_TEXT(r.begin_waiting_date, 'datetime') AS begin_waiting_date",
        "TEMPORAL_TEXT(r.time_to_resolve, 'datetime') AS time_to_resolve",
    ];

    private const ITIL_FIELDS = [
        'l.id AS linkid', 'r.id', 'r.name', 'r.status', 'r.priority',
        'IDENTITY(r.entities) AS entities_id', 'IDENTITY(r.itilcategories) AS itilcategories_id',
        "TEMPORAL_TEXT(r.date, 'datetime') AS date", "TEMPORAL_TEXT(r.date_mod, 'datetime') AS date_mod",
    ];

    public function __construct(private EntityManager $em)
    {
    }

    public function ticketsForChange(?int $change): array
    {
        $query = $this->em->createQueryBuilder()->select(...self::TICKET_FIELDS)->distinct()
            ->from(Entity\ChangeTicket::class, 'l')->leftJoin('l.tickets', 'r')->orderBy('r.name');
        return $this->endpoint($query, 'l.changes', $change)->getQuery()->getScalarResult();
    }

    public function changesForTicket(?int $ticket): array
    {
        $query = $this->em->createQueryBuilder()->select(...self::ITIL_FIELDS)->distinct()
            ->from(Entity\ChangeTicket::class, 'l')->leftJoin('l.changes', 'r')->orderBy('r.name');
        return $this->endpoint($query, 'l.tickets', $ticket)->getQuery()->getScalarResult();
    }

    /** NULL scope means cron: retain the original unscoped LEFT JOIN/name order. */
    public function ticketsForProblem(?int $problem, ?EntityRestriction $scope): array
    {
        $query = $this->em->createQueryBuilder()->select(...self::TICKET_FIELDS)
            ->from(Entity\ProblemTicket::class, 'l')->leftJoin('l.tickets', 'r');
        $this->visibility($query, Entity\Ticket::class, $scope);
        return $this->endpoint($query, 'l.problems', $problem)->getQuery()->getScalarResult();
    }

    public function problemsForTicket(?int $ticket, ?EntityRestriction $scope): array
    {
        $query = $this->em->createQueryBuilder()->select(...self::ITIL_FIELDS)
            ->from(Entity\ProblemTicket::class, 'l')->leftJoin('l.problems', 'r');
        $this->visibility($query, Entity\Problem::class, $scope);
        return $this->endpoint($query, 'l.tickets', $ticket)->getQuery()->getScalarResult();
    }

    private function endpoint(QueryBuilder $query, string $association, ?int $id): QueryBuilder
    {
        return $id === null ? $query->andWhere($association . ' IS NULL')
            : $query->andWhere('IDENTITY(' . $association . ') = :parent')->setParameter('parent', $id, Types::BIGINT);
    }

    /** Consume the shared permission calculation; do not reproduce its recursion or sentinel rules. */
    private function visibility(QueryBuilder $query, string $target, ?EntityRestriction $scope): void
    {
        if ($scope !== null) {
            $query->innerJoin('r.entities', 'scopeEntity')->addSelect('scopeEntity.id AS entity')
                ->andWhere((new RecordCriteria($query, $this->em->getClassMetadata($target)))->where($scope->criteria))
                ->orderBy('scopeEntity.completename');
        }
        $query->addOrderBy('r.name');
    }
}
