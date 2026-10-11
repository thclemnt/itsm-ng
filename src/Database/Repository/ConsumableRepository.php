<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
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
            ->set('c.recipientGroup', ':replacement')->setParameter('replacement', $replacement === 0 ? null : $replacement, Types::INTEGER)
            ->where('IDENTITY(c.recipientGroup) = :group')->setParameter('group', $group, Types::INTEGER);
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

    public function give(int $id, string $itemtype, int $recipient): bool
    {
        $association = Entity\Consumable::referenceAssociation($itemtype);
        if ($recipient <= 0) {
            throw new InvalidArgumentException('Consumable recipient requires a positive identifier');
        }
        $target = $this->em->getClassMetadata(Entity\Consumable::class)->getAssociationTargetClass($association);
        if ($this->em->getRepository($target)->find($recipient) === null) {
            return false;
        }
        $updated = $this->em->createQueryBuilder()->update(Entity\Consumable::class, 'c')
            ->set('c.date_out', ':today')->setParameter('today', new DateTimeImmutable('today'), Types::DATE_IMMUTABLE)
            ->set('c.itemtype', ':type')->setParameter('type', $itemtype, Types::STRING)
            ->set('c.recipientUser', $association === 'recipientUser' ? ':recipient' : 'NULL')
            ->set('c.recipientGroup', $association === 'recipientGroup' ? ':recipient' : 'NULL')
            ->setParameter('recipient', $recipient, Types::INTEGER)
            ->where('c.id = :id')->setParameter('id', $id, Types::INTEGER)->getQuery()->execute() > 0;
        return $updated || (bool)$this->em->createQueryBuilder()->select('COUNT(c.id)')->from(Entity\Consumable::class, 'c')
            ->where('c.id = :id')->setParameter('id', $id, Types::INTEGER)->getQuery()->getSingleScalarResult();
    }

    public function releaseUser(int $user): void
    {
        $this->em->createQueryBuilder()->update(Entity\Consumable::class, 'c')
            ->set('c.recipientUser', 'NULL')->set('c.itemtype', 'NULL')->set('c.date_out', 'NULL')
            ->where('IDENTITY(c.recipientUser) = :user')->setParameter('user', $user, Types::INTEGER)->getQuery()->execute();
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
        return (new RecordRepository($this->em))->toRows($query->getQuery()->toIterable());
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

    public function alertCandidates(int $entity, DateTimeImmutable $before): array
    {
        $query = $this->em->createQueryBuilder()->select('r.id AS consID', 'IDENTITY(r.entities) AS entity', 'r.ref AS ref', 'r.name AS name', 'r.alarm_threshold AS threshold', 'a.id AS alertID', 'a.date AS date')
            ->from(Entity\ConsumableItem::class, 'r')->leftJoin(Entity\Alert::class, 'a', 'WITH', 'a.consumableItem = r')

            ->where('r.is_deleted = :false AND r.alarm_threshold >= 0 AND IDENTITY(r.entities) = :entity')
            ->setParameter('false', false, Types::BOOLEAN)->setParameter('entity', $entity, Types::INTEGER)
            ->andWhere('a.date IS NULL OR a.date < :before')->setParameter('before', $before, Types::DATETIMETZ_IMMUTABLE)
            ->orderBy('r.id')->addOrderBy('a.id');
        $rows = $query->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            if ($row['date'] !== null) {
                $date = $row['date'] instanceof DateTimeInterface ? $row['date'] : new DateTimeImmutable($row['date']);
                $row['date'] = $date->format('Y-m-d H:i:s');
            }
        }
        return $rows;
    }
}
