<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\Pagination\Paginator;
use itsmng\Database\Entity\ItemTicket;
use itsmng\Reporting\MonthSeries;

/** Ticket counts by associated item, with pagination applied to grouped rows. */
final class TicketAssetStatisticsRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function page(string $begin, string $end, ?array $entities, int $offset = 0, ?int $limit = null): array
    {
        $first = MonthSeries::date($begin);
        $last = MonthSeries::date($end);
        if ($entities === [] || ($first !== null && $last !== null && $first > $last)) {
            return ['total' => 0, 'rows' => []];
        }
        $query = $this->em->createQueryBuilder()
            ->from(ItemTicket::class, 'i')
            ->join('i.tickets', 't')
            ->where("i.itemtype IS NOT NULL AND i.itemtype <> '' AND i.items_id > 0");
        if ($entities !== null) {
            $query->andWhere('IDENTITY(t.entities) IN (:entities)')
                ->setParameter('entities', array_map('intval', array_values($entities)), ArrayParameterType::INTEGER);
        }
        if ($first !== null) {
            $query->andWhere('t.date >= :begin')
                ->setParameter('begin', $first, Types::DATETIMETZ_IMMUTABLE);
        }
        if ($last !== null) {
            $dateOnly = strlen($end) === 10;
            $query->andWhere('t.date' . ($dateOnly ? ' < :end' : ' <= :end'))
                ->setParameter('end', $dateOnly ? $last->modify('+1 day') : $last, Types::DATETIMETZ_IMMUTABLE);
        }
        $query->select('i.itemtype', 'i.items_id', 'COUNT(DISTINCT t.id) AS NB')
            ->groupBy('i.itemtype', 'i.items_id')
            ->orderBy('NB', 'DESC')
            ->addOrderBy('i.itemtype')
            ->addOrderBy('i.items_id');
        // Doctrine counts the grouped subquery, preserving the database's collation.
        $total = count((new Paginator($query, fetchJoinCollection: false))->setUseOutputWalkers(true));
        if ($limit !== null && $limit <= 0) {
            return ['total' => $total, 'rows' => []];
        }
        $query->setFirstResult(max(0, $offset))
            ->setMaxResults($limit);
        $rows = $query->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            $row['items_id'] = (int)$row['items_id'];
            $row['NB'] = (int)$row['NB'];
        }
        return ['total' => $total, 'rows' => $rows];
    }
}
