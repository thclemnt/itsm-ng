<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use itsmng\Database\Entity\ItemTicket;
use itsmng\Database\Entity\TicketCost;

/** Ticket views of an asset follow its owning, constrained item association. */
final class TicketAssetRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $kind): bool
    {
        try {
            ItemTicket::referenceAssociation($kind);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function activeOrRecent(string $kind, int $asset, array $finished, int $days, ?DateTimeImmutable $now = null): array
    {
        $query = $this->linked($kind, $asset);
        if ($query === null) {
            return [];
        }
        if ($finished) {
            $recent = "DATE_ADD(t.solvedate, :days, 'DAY') > " . $this->time($query, $now);
            $query->andWhere('t.status NOT IN (:finished) OR ' . $recent)->setParameter('finished', $finished)
                ->setParameter('days', $days, Types::INTEGER);
        }
        // With no finished status every ticket is active, so no date filter applies.
        $query->select('t.id, t.name')->orderBy('t.id');
        return array_column($query->getQuery()->getScalarResult(), 'name', 'id');
    }

    /** Historical counters deliberately include soft-deleted tickets. */
    public function activeCount(string $kind, int $asset, array $finished): int
    {
        $query = $this->linked($kind, $asset);
        if ($query === null) {
            return 0;
        }
        if ($finished) {
            $query->andWhere('t.status NOT IN (:finished)')->setParameter('finished', $finished);
        }
        return (int)$query->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
    }

    public function active(string $kind, int $asset, array $finished, int $type): array
    {
        $projected = (new ITILAssetRepository($this->em))->projectActiveForItem(ItemTicket::class, 'tickets', $kind, $asset, $finished, $type);
        if ($projected !== null) {
            return $projected;
        }
        $query = $this->linked($kind, $asset);
        if ($query === null) {
            return [];
        }
        $query->select('t.id, t.name, t.priority')->andWhere('t.is_deleted = :no AND t.type = :type')
            ->setParameter('no', false, Types::BOOLEAN)->setParameter('type', $type, Types::INTEGER);
        if ($finished) {
            $query->andWhere('t.status NOT IN (:finished)')->setParameter('finished', $finished);
        }
        $rows = $query->orderBy('t.id')->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['priority'] = (int)$row['priority'];
        }
        return $rows;
    }

    public function recentlyFinishedCount(string $kind, int $asset, array $finished, int $days, ?DateTimeImmutable $now = null): int
    {
        $query = $this->linked($kind, $asset);
        if ($query === null || !$finished) {
            return 0;
        }
        $query->andWhere('t.status IN (:finished)')->setParameter('finished', $finished)
            ->andWhere("DATE_ADD(t.solvedate, :days, 'DAY') > " . $this->time($query, $now))
            ->setParameter('days', $days, Types::INTEGER);
        return (int)$query->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
    }

    /** Keep individual cost rows for the public currency/rounding calculation. */
    public function costs(string $kind, int $asset): array
    {
        if (!self::supports($kind) || $asset <= 0) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('c')->from(TicketCost::class, 'c')
            ->join(ItemTicket::class, 'i', 'WITH', 'i.tickets = c.tickets')
            ->where('IDENTITY(i.' . ItemTicket::referenceAssociation($kind) . ') = :asset')
            ->setParameter('asset', $asset, Types::BIGINT)
            ->andWhere('c.cost_time > 0 OR c.cost_fixed > 0 OR c.cost_material > 0')->orderBy('c.id');
        return $this->rows($query);
    }

    public function transferRows(string $kind, int $asset): array
    {
        $query = $this->linked($kind, $asset);
        if ($query === null) {
            return [];
        }
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->select('i, t')->orderBy('t.id')->getQuery()->toIterable() as $link) {
            $rows[] = $records->toRow($link->tickets) + ['_relid' => $link->id];
        }
        return $rows;
    }

    private function linked(string $kind, int $asset): ?QueryBuilder
    {
        if (!self::supports($kind) || $asset <= 0) {
            return null;
        }
        return $this->em->createQueryBuilder()->from(ItemTicket::class, 'i')->join('i.tickets', 't')
            ->where('IDENTITY(i.' . ItemTicket::referenceAssociation($kind) . ') = :asset')->setParameter('asset', $asset, Types::BIGINT);
    }

    private function time(QueryBuilder $query, ?DateTimeImmutable $now): string
    {
        if ($now === null) {
            return 'CURRENT_TIMESTAMP()';
        }
        $query->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE);
        return ':now';
    }

    private function rows(QueryBuilder $query): array
    {
        return (new RecordRepository($this->em))->toRows($query->getQuery()->toIterable());
    }
}
