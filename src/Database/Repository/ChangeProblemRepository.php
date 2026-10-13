<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\ChangeProblem;

/** Scalar tab facts follow the fixed native Change/Problem owning endpoints. */
final class ChangeProblemRepository
{
    private const FIELDS = [
        'l.id AS linkid', 'r.id', 'r.name', 'r.status', 'r.priority', 'IDENTITY(r.entities) AS entities_id',
        "TEMPORAL_TEXT(r.date, 'datetime') AS date", "TEMPORAL_TEXT(r.date_mod, 'datetime') AS date_mod",
        "TEMPORAL_TEXT(r.closedate, 'datetime') AS closedate", "TEMPORAL_TEXT(r.solvedate, 'datetime') AS solvedate",
        "TEMPORAL_TEXT(r.begin_waiting_date, 'datetime') AS begin_waiting_date",
        "TEMPORAL_TEXT(r.time_to_resolve, 'datetime') AS time_to_resolve",
    ];

    public function __construct(private EntityManager $em)
    {
    }

    public function changesForProblem(?int $problem): array
    {
        $query = $this->em->createQueryBuilder()->select(...self::FIELDS)->distinct()
            ->from(ChangeProblem::class, 'l')->leftJoin('l.changes', 'r')->orderBy('r.name');
        if ($problem === null) {
            $query->where('l.problems IS NULL');
        } else {
            $query->where('IDENTITY(l.problems) = :problem')->setParameter('problem', $problem, Types::BIGINT);
        }
        return $query->getQuery()->getScalarResult();
    }

    public function problemsForChange(?int $change): array
    {
        $query = $this->em->createQueryBuilder()->select(...self::FIELDS)->distinct()
            ->from(ChangeProblem::class, 'l')->leftJoin('l.problems', 'r')->orderBy('r.name');
        if ($change === null) {
            $query->where('l.changes IS NULL');
        } else {
            $query->where('IDENTITY(l.changes) = :change')->setParameter('change', $change, Types::BIGINT);
        }
        return $query->getQuery()->getScalarResult();
    }
}
