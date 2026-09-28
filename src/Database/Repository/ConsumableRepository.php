<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\RecordCriteria;

final class ConsumableRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Group purge returns assigned stock; replacement preserves its usage dates. */
    public function replaceGroup(int $group, int $replacement): void
    {
        $query = $this->em->createQueryBuilder()->update(Entity\Consumable::class, 'c')
            ->set('c.items_id', ':replacement')->setParameter('replacement', $replacement)
            ->where('c.itemtype = :type AND c.items_id = :group')->setParameter('type', 'Group')->setParameter('group', $group);
        if ($replacement === 0) {
            $query->set('c.itemtype', 'NULL')->set('c.date_out', 'NULL');
        }
        $query->getQuery()->execute();
    }

    /** Returning stock keeps its last recipient as historical information. */
    public function returnToStock(int $id): void
    {
        $this->em->createQueryBuilder()->update(Entity\Consumable::class, 'c')->set('c.date_out', 'NULL')
            ->where('c.id = :id')->setParameter('id', $id, Types::INTEGER)->getQuery()->execute();
    }

    public function give(int $id, string $itemtype, int $recipient): void
    {
        $this->em->createQueryBuilder()->update(Entity\Consumable::class, 'c')
            ->set('c.date_out', ':today')->setParameter('today', new \DateTimeImmutable('today'), Types::DATE_IMMUTABLE)
            ->set('c.itemtype', ':type')->setParameter('type', $itemtype, Types::STRING)
            ->set('c.items_id', ':recipient')->setParameter('recipient', $recipient, Types::INTEGER)
            ->where('c.id = :id')->setParameter('id', $id, Types::INTEGER)->getQuery()->execute();
    }

    public function forModel(int $model, bool $used, int $limit, int $offset): array
    {
        $query = $this->em->createQueryBuilder()->select('c')->from(Entity\Consumable::class, 'c')
            ->where('c.consumableitems = :model')->setParameter('model', $model, Types::INTEGER)
            ->andWhere('c.date_out IS ' . ($used ? 'NOT NULL' : 'NULL'));
        if ($used) {
            $query->orderBy('c.date_out', 'DESC');
        }
        $query->addSelect('CASE WHEN c.date_in IS NULL THEN 0 ELSE 1 END AS HIDDEN date_order')
            ->addOrderBy('date_order')->addOrderBy('c.date_in')->addOrderBy('c.id')
            ->setMaxResults(max(1, $limit))->setFirstResult(max(0, $offset));
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return $rows;
    }

    /** Scope belongs to the consumable model, not a cached child entity or recipient. */
    public function summary(array $scope, bool $used): array
    {
        $query = $this->em->createQueryBuilder()->select('COUNT(c.id) AS count', 'r.id AS consumableitems_id')
            ->from(Entity\Consumable::class, 'c')->innerJoin('c.consumableitems', 'r')
            ->where('c.date_out IS ' . ($used ? 'NOT NULL' : 'NULL'));
        $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata(Entity\ConsumableItem::class)))->where($scope))
            ->groupBy('r.id')->orderBy('r.id');
        if ($used) {
            $query->addSelect('c.itemtype AS itemtype', 'c.items_id AS items_id')
                ->addGroupBy('c.itemtype, c.items_id')->addOrderBy('c.itemtype')->addOrderBy('c.items_id');
        }
        return $query->getQuery()->getScalarResult();
    }

    public function alertCandidates(int $entity, \DateTimeImmutable $before): array
    {
        $query = $this->em->createQueryBuilder()->select('r.id AS consID', 'r.entities_id AS entity', 'r.ref AS ref', 'r.name AS name', 'r.alarm_threshold AS threshold', 'a.id AS alertID', 'a.date AS date')
            ->from(Entity\ConsumableItem::class, 'r')->leftJoin(Entity\Alert::class, 'a', 'WITH', 'a.items_id = r.id AND a.itemtype = :type')
            ->setParameter('type', 'ConsumableItem', Types::STRING)
            ->where('r.is_deleted = :false AND r.alarm_threshold >= 0 AND r.entities_id = :entity')
            ->setParameter('false', false, Types::BOOLEAN)->setParameter('entity', $entity, Types::INTEGER)
            ->andWhere('a.date IS NULL OR a.date < :before')->setParameter('before', $before, Types::DATETIMETZ_IMMUTABLE)
            ->orderBy('r.id')->addOrderBy('a.id');
        $rows = $query->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            if ($row['date'] !== null) {
                $date = $row['date'] instanceof \DateTimeInterface ? $row['date'] : new \DateTimeImmutable($row['date']);
                $row['date'] = $date->format('Y-m-d H:i:s');
            }
        }
        return $rows;
    }
}
