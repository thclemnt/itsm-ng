<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

final class ReservationItemRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $type): bool
    {
        return isset(EntityRegistry::TABLES[\getTableForItemType($type)]);
    }

    public function types(array $entities): array
    {
        return array_column($this->em->createQueryBuilder()->select('DISTINCT i.itemtype')
            ->from(Entity\ReservationItem::class, 'i')->where('i.is_active = :active AND IDENTITY(i.entities) IN (:entities)')
            ->setParameter('active', true, Types::BOOLEAN)->setParameter('entities', $entities ?: [-1])
            ->orderBy('i.itemtype')->getQuery()->getScalarResult(), 'itemtype');
    }

    public function peripheralTypes(array $entities): array
    {
        return $this->em->createQueryBuilder()->select('DISTINCT t.id', 't.name')
            ->from(Entity\ReservationItem::class, 'i')
            ->innerJoin(Entity\Peripheral::class, 'p', 'WITH', 'i.items_id = p.id AND i.itemtype = :type')
            ->join('p.peripheraltypes', 't')
            ->where('i.is_active = :active AND IDENTITY(i.entities) IN (:entities)')
            ->setParameter('active', true, Types::BOOLEAN)->setParameter('type', 'Peripheral')
            ->setParameter('entities', $entities ?: [-1])->orderBy('t.name')->addOrderBy('t.id')->getQuery()->getScalarResult();
    }

    /** Polymorphic asset types resolve through the explicit core entity registry. */
    public function available(string $type, string $nameField, array $scope, ?string $begin, ?string $end, ?int $peripheralType = null): array
    {
        $class = EntityRegistry::TABLES[\getTableForItemType($type)];
        $metadata = $this->em->getClassMetadata($class);
        $query = $this->em->createQueryBuilder()->from($class, 'r')
            ->innerJoin(Entity\ReservationItem::class, 'i', 'WITH', 'i.items_id = r.id AND i.itemtype = :type')
            ->leftJoin('r.locations', 'l');
        $compiler = new RecordCriteria($query, $metadata);
        $query->select(
            'i.id',
            'i.comment',
            $compiler->column($nameField) . ' AS name',
            'IDENTITY(r.entities) AS entities_id',
            $metadata->hasField('otherserial') ? 'r.otherserial AS otherserial' : "'' AS otherserial",
            'l.id AS location',
            'l.completename AS location_name',
            'r.id AS items_id'
        )
            ->where($compiler->where($scope))->andWhere('i.is_active = :active AND i.is_deleted = :deleted')
            ->setParameter('type', $type)->setParameter('active', true, Types::BOOLEAN)->setParameter('deleted', false, Types::BOOLEAN);
        if ($metadata->hasField('is_deleted')) {
            $query->andWhere('r.is_deleted = :deleted');
        }
        if ($begin !== null && $end !== null) {
            $query->andWhere('NOT EXISTS (SELECT booking.id FROM ' . Entity\Reservation::class . ' booking WHERE booking.reservationitems = i.id AND booking.end > :begin AND booking.begin < :end)')
                ->setParameter('begin', new \DateTime($begin), Types::DATETIMETZ_MUTABLE)
                ->setParameter('end', new \DateTime($end), Types::DATETIMETZ_MUTABLE);
        }
        if ($type === 'Peripheral' && $peripheralType !== null) {
            $query->andWhere('IDENTITY(r.peripheraltypes) = :peripheralType')->setParameter('peripheralType', $peripheralType, Types::INTEGER);
        }
        return $query->orderBy('r.entities')->addOrderBy($compiler->column($nameField))->addOrderBy('i.id')->getQuery()->getScalarResult();
    }
}
