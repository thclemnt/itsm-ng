<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

/** Cost history and totals for the five core parent/child associations. */
final class CostRepository
{
    private const TYPES = [
        'ContractCost' => [Entity\ContractCost::class, 'contracts'],
        'ProjectCost' => [Entity\ProjectCost::class, 'projects'],
        'TicketCost' => [Entity\TicketCost::class, 'tickets'],
        'ProblemCost' => [Entity\ProblemCost::class, 'problems'],
        'ChangeCost' => [Entity\ChangeCost::class, 'changes'],
    ];

    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public function rows(string $type, int|array $parents, bool $last = false): array
    {
        [$class, $association] = self::TYPES[$type] ?? throw new \InvalidArgumentException('Unmapped cost type');
        $query = $this->em->createQueryBuilder()->select('c')->from($class, 'c')
            ->where('c.' . $association . ' IN (:parents)')->setParameter('parents', (array)$parents ?: [-1]);
        $date = $last ? 'end_date' : 'begin_date';
        // MySQL's date ordering: NULL first ascending, last descending.
        $query->addSelect('CASE WHEN c.' . $date . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN date_present')
            ->orderBy('date_present', $last ? 'DESC' : 'ASC')->addOrderBy('c.' . $date, $last ? 'DESC' : 'ASC')
            ->addOrderBy('c.id', $last ? 'DESC' : 'ASC');
        if ($last) {
            $query->setMaxResults(1);
        }
        $rows = [];
        $records = new RecordRepository($this->em);
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return $rows;
    }

    public function actionTime(string $type, int $parent): ?int
    {
        [$class, $association] = self::TYPES[$type] ?? throw new \InvalidArgumentException('Unmapped cost type');
        if (!in_array($type, ['TicketCost', 'ProblemCost', 'ChangeCost'], true)) {
            throw new \InvalidArgumentException('Cost type has no action time');
        }
        $value = $this->em->createQueryBuilder()->select('SUM(c.actiontime)')->from($class, 'c')
            ->where('c.' . $association . ' = :parent')->setParameter('parent', $parent)
            ->getQuery()->getSingleScalarResult();
        return $value === null ? null : (int)$value;
    }
}
