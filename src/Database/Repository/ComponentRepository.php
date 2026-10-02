<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

/** Component assignment persistence; asset access and lifecycle hooks remain with callers. */
final class ComponentRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Null entity scope means all entities; an empty scope admits no attached assets. */
    public function forDevice(string $table, string $deviceColumn, int $device, ?string $assetType, ?string $assetTable, ?array $entities): array
    {
        $class = EntityRegistry::tables()[$table];
        $query = $this->em->createQueryBuilder()->select('r')->from($class, 'r');
        $criteria = new RecordCriteria($query, $this->em->getClassMetadata($class), false);
        $query->where($criteria->where([$deviceColumn => $device, 'itemtype' => $assetType, 'is_deleted' => false]));
        if ($assetTable !== null && $entities !== null) {
            if (!$entities) {
                return [];
            }
            // The asset side is polymorphic: join the registered concrete entity.
            $query->innerJoin(EntityRegistry::tables()[$assetTable], 'a', 'WITH', 'a.id = r.items_id')
                ->andWhere('IDENTITY(a.entities) IN (:entities)')->setParameter('entities', $entities);
        }
        $query->orderBy('r.itemtype')->addOrderBy('r.items_id')->addOrderBy('r.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return $rows;
    }

    /** Returning components to stock deliberately does not run per-component update hooks. */
    public function detach(string $table, string $assetType, int $asset): int
    {
        return $this->em->createQueryBuilder()->update(EntityRegistry::tables()[$table], 'r')
            ->set('r.items_id', ':stock')->setParameter('stock', 0, Types::INTEGER)
            ->set('r.itemtype', ':empty')->setParameter('empty', '', Types::STRING)
            ->where('r.itemtype = :type')->setParameter('type', $assetType, Types::STRING)
            ->andWhere('r.items_id = :asset')->setParameter('asset', $asset, Types::INTEGER)
            ->getQuery()->execute();
    }
}
