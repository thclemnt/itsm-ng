<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;

/** Financial report data; authorization scope is supplied by the report controller. */
final class FinancialRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $itemtype): bool
    {
        return isset(EntityRegistry::tables()[\getTableForItemType($itemtype)]);
    }

    public function rows(string $itemtype, string $begin, string $end, ?array $entities, bool $assets): array
    {
        $class = EntityRegistry::tables()[\getTableForItemType($itemtype)];
        $query = $this->em->createQueryBuilder()->select('i')->from(Entity\Infocom::class, 'i')
            ->innerJoin($class, 'a', 'WITH', 'a.id = i.items_id')
            ->where('i.itemtype = :itemtype')->setParameter('itemtype', $itemtype);
        $scope = 'a';
        if ($assets) {
            $query->addSelect('a.name AS name, a.ticket_tco AS ticket_tco, e.completename AS entname, e.id AS entID')
                ->leftJoin('a.entities', 'e')
                ->andWhere('a.is_template = :false')->setParameter('false', false, Types::BOOLEAN)
                ->orderBy('e.completename')->addOrderBy('i.buy_date')->addOrderBy('i.use_date');
        } elseif ($itemtype === 'SoftwareLicense') {
            $query->innerJoin('a.softwares', 's');
        } elseif (is_a($itemtype, \CommonDBChild::class, true)) {
            $parent = EntityRegistry::tables()[$itemtype::$itemtype::getTable()];
            $metadata = $this->em->getClassMetadata($class);
            $column = $itemtype::$items_id;
            $field = $metadata->getFieldName($column);
            foreach ($metadata->associationMappings as $property => $mapping) {
                if ($mapping->joinColumns[0]->name === $column) {
                    $field = $property;
                }
            }
            $query->innerJoin($parent, 'p', 'WITH', 'p.id = a.' . $field);
            $scope = 'p';
        }
        if ($entities !== null) {
            $query->andWhere('IDENTITY(' . $scope . '.entities) IN (:entities)')->setParameter('entities', $entities ?: [-1]);
        }
        $dates = [];
        foreach (['buy_date', 'use_date'] as $field) {
            $bounds = [];
            if ($begin !== '') {
                $bounds[] = 'i.' . $field . ' >= :begin';
                $query->setParameter('begin', new \DateTimeImmutable($begin), Types::DATE_IMMUTABLE);
            }
            if ($end !== '') {
                $bounds[] = 'i.' . $field . ' <= :end';
                $query->setParameter('end', new \DateTimeImmutable($end), Types::DATE_IMMUTABLE);
            }
            if ($bounds) {
                $dates[] = '(' . implode(' AND ', $bounds) . ')';
            }
        }
        if ($dates) {
            $query->andWhere('(' . implode(' OR ', $dates) . ')');
        }
        $query->addOrderBy('i.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            if (is_array($result)) {
                $row = $records->toRow($result[0]);
                $this->em->detach($result[0]);
                unset($result[0]);
                $rows[] = array_merge($row, $result);
            } else {
                $rows[] = $records->toRow($result);
                $this->em->detach($result);
            }
        }
        return $rows;
    }
}
