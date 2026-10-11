<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

/** Lightweight mapped labels for statistics trees and asset classifications. */
final class StatisticsClassificationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function options(string $table, string $label, array $criteria, array|string $order): array
    {
        $class = EntityRegistry::tables()[$table] ?? throw new InvalidArgumentException('Unmapped statistics classification');
        $query = $this->em->createQueryBuilder()
            ->from($class, 'r');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata($class));
        $query->select('r.id AS id', $compiler->column($label) . ' AS link')
            ->where($compiler->where($criteria));
        $compiler->order($order);
        $query->addOrderBy('r.id');
        $rows = $query->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
        }
        return $rows;
    }
}
