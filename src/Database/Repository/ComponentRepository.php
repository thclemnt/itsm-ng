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
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference !== null && ($assetType === '' || $assetType === null) && isset($reference['empty_value'])) {
            $assetType = null;
        } elseif ($reference !== null && !isset($reference['selections'][$assetType])) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('r')->from($class, 'r');
        $criteria = new RecordCriteria($query, $this->em->getClassMetadata($class), false);
        $query->where($criteria->where([$deviceColumn => $device, 'itemtype' => $assetType, 'is_deleted' => false]));
        if ($assetTable !== null && $entities !== null) {
            if (!$entities) {
                return [];
            }
            if ($reference !== null) {
                if (($reference['selections'][$assetType]['target'] ?? null) !== $assetTable) {
                    return [];
                }
                $query->innerJoin('r.' . $class::referenceAssociation($assetType), 'a');
            } else {
                $query->innerJoin(EntityRegistry::tables()[$assetTable], 'a', 'WITH', 'a.id = r.items_id');
            }
            $query->andWhere('IDENTITY(a.entities) IN (:entities)')->setParameter('entities', $entities);
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
        $class = EntityRegistry::tables()[$table];
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference !== null) {
            if (!isset($reference['empty_value'])) {
                throw new \LogicException('Returning components to stock requires an optional owning subject.');
            }
            if (!isset($reference['selections'][$assetType])) {
                return 0;
            }
            $association = $class::referenceAssociation($assetType);
            return $this->em->createQueryBuilder()->update($class, 'r')
                ->set('r.' . $association, 'NULL')->set('r.itemtype', 'NULL')
                ->where('r.itemtype = :type')->setParameter('type', $assetType, Types::STRING)
                ->andWhere('IDENTITY(r.' . $association . ') = :asset')->setParameter('asset', $asset, Types::BIGINT)
                ->getQuery()->execute();
        }
        return $this->em->createQueryBuilder()->update(EntityRegistry::tables()[$table], 'r')
            ->set('r.items_id', ':stock')->setParameter('stock', 0, Types::INTEGER)
            ->set('r.itemtype', ':empty')->setParameter('empty', '', Types::STRING)
            ->where('r.itemtype = :type')->setParameter('type', $assetType, Types::STRING)
            ->andWhere('r.items_id = :asset')->setParameter('asset', $asset, Types::INTEGER)
            ->getQuery()->execute();
    }

    /** Complete stock candidates for the device's actual specificities, including legacy deleted stock. */
    public function stock(string $table, string $deviceColumn, int $device): array
    {
        $class = EntityRegistry::tables()[$table];
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference !== null && !isset($reference['empty_value'])) {
            throw new \LogicException('A required component subject has no stock state.');
        }
        $kind = $reference !== null ? null : '';
        return (new RecordRepository($this->em))->matching($table, [$deviceColumn => $device, 'itemtype' => $kind], ['id']);
    }

    /** Assignment rows for a transfer; exclusion is of device owners, never binding IDs. */
    public function assigned(string $table, string $deviceColumn, string $kind, int $asset, array $excludedDevices): array
    {
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference !== null && !isset($reference['selections'][$kind])) {
            return [];
        }
        $identity = $reference['selections'][$kind]['column'] ?? 'items_id';
        $criteria = ['itemtype' => $kind, $identity => $asset];
        if ($excludedDevices) {
            $criteria['NOT'] = [$deviceColumn => $excludedDevices];
        }
        return (new RecordRepository($this->em))->matching($table, $criteria, ['id']);
    }

    /** Stock or any asset outside the selected transfer graph requires copying the device. */
    public function canMoveDevice(string $table, string $deviceColumn, int $device, array $movingAssets): bool
    {
        $class = EntityRegistry::tables()[$table];
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        $identity = $reference !== null && count($reference['selections']) === 1
            ? 'IDENTITY(r.' . $class::referenceAssociation(array_key_first($reference['selections'])) . ')' : 'r.items_id';
        $query = $this->em->createQueryBuilder()->select('r.itemtype AS kind', $identity . ' AS asset')->from($class, 'r');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata($class), false))->where([$deviceColumn => $device]));
        foreach ($query->getQuery()->toIterable() as $row) {
            if (!isset($movingAssets[$row['kind'] ?? ''][(int)$row['asset']])) {
                return false;
            }
        }
        return true;
    }

    /** Low-level transfer rebinding retains the existing deliberate absence of per-link update hooks. */
    public function rebind(string $table, int $binding, string $deviceColumn, int $device, string $kind, int $asset): bool
    {
        $class = EntityRegistry::tables()[$table];
        if ($this->em->find($class, $binding) === null) {
            return false;
        }
        $values = [$deviceColumn => $device, 'itemtype' => $kind, 'items_id' => $asset];
        if (is_a($class, \itsmng\Database\Mapping\LegacyInput::class, true) && method_exists($class, 'withReference')) {
            $values = $class::withReference($values, $kind, $asset);
        }
        (new RecordWriter($this->em))->update($table, $binding, $values);
        return true;
    }
}
