<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\RecordCriteria;
use LogicException;

/** Component assignment persistence; asset access and lifecycle hooks remain with callers. */
final class ComponentRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Count attached rows in one operation; duplicate affinities retain their multiplicity. */
    public function countForAsset(array $tables, string $type, int $id): int
    {
        $count = 0;
        foreach ($tables as $table) {
            $class = EntityRegistry::tables()[$table];
            $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
            if ($reference !== null && !isset($reference['selections'][$type])) {
                continue;
            }
            $subject = $reference === null ? 'r.items_id' : 'IDENTITY(r.' . $class::referenceAssociation($type) . ')';
            $count += (int)$this->em->createQueryBuilder()
                ->select('COUNT(r.id)')
                ->from($class, 'r')
                ->where($subject . ' = :asset AND r.itemtype = :kind AND r.is_deleted = :deleted')
                ->setParameter('asset', $id, Types::BIGINT)
                ->setParameter('kind', $type, Types::STRING)
                ->setParameter('deleted', false, Types::BOOLEAN)
                ->getQuery()
                ->getSingleScalarResult();
        }
        return $count;
    }

    /** One private family count; retain the same scalar result and bound conversions as DQL. */
    public function nativeCountForAsset(string $table, string $type, int $id, ?array $mapping = null): int
    {
        $class = EntityRegistry::tables()[$table];
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference !== null && !isset($reference['selections'][$type])) {
            return 0;
        }
        $metadata = $mapping === null ? $this->em->getClassMetadata($class) : null;
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        if ($mapping !== null) {
            $identifier = static fn (array $name): string => $name[1] ? $platform->quoteSingleIdentifier($name[0]) : $name[0];
            $column = static fn (string $field): string => 'r.' . $identifier($mapping['fields'][$field]);
            $from = static fn (): string => $identifier($mapping['table']);
            if ($reference === null) {
                $subject = $column('items_id');
            } else {
                $join = $mapping['subjects'][$class::referenceAssociation($type)] ?? null;
                if ($join === null) {
                    throw new LogicException('Component counts require a single owning subject reference.');
                }
                $subject = 'r.' . $identifier($join);
            }
        } else {
            $quote = $this->em->getConfiguration()->getQuoteStrategy();
            $column = static fn (string $field): string => 'r.' . $quote->getColumnName($field, $metadata, $platform);
            $from = static fn (): string => $quote->getTableName($metadata, $platform);
            if ($reference === null) {
                $subject = $column('items_id');
            } else {
                $association = $metadata->associationMappings[$class::referenceAssociation($type)];
                if (!$association->isToOneOwningSide() || count($association->joinColumns) !== 1) {
                    throw new LogicException('Component counts require a single owning subject reference.');
                }
                $subject = 'r.' . $quote->getJoinColumnName($association->joinColumns[0], $metadata, $platform);
            }
        }
        $asset = Type::getType(Types::BIGINT);
        $kind = Type::getType(Types::STRING);
        $deleted = Type::getType(Types::BOOLEAN);
        // COUNT's path and scalar hydration do not apply mapped SQL/PHP output converters.
        return (int)$connection->createQueryBuilder()
            ->select('COUNT(' . $column('id') . ')')
            ->from($from(), 'r')
            ->where($subject . ' = ' . $asset->convertToDatabaseValueSQL('?', $platform))
            ->andWhere($column('itemtype') . ' = ' . $kind->convertToDatabaseValueSQL('?', $platform))
            ->andWhere($column('is_deleted') . ' = ' . $deleted->convertToDatabaseValueSQL('?', $platform))
            ->setParameter(0, $id, Types::BIGINT)
            ->setParameter(1, $type, Types::STRING)
            ->setParameter(2, false, Types::BOOLEAN)
            ->executeQuery()
            ->fetchOne();
    }

    /** A selected typed subject must exist; stock deliberately selects no subject. */
    public function hasSelectedSubject(string $table, array $values): bool
    {
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference === null) {
            return true;
        }
        $kind = $values[$reference['discriminator']] ?? null;
        if ($kind === null && array_key_exists('empty_value', $reference)) {
            return true;
        }
        $selection = is_string($kind) ? ($reference['selections'][$kind] ?? null) : null;
        if ($selection === null || !isset($values[$selection['column']])) {
            return false;
        }
        $class = EntityRegistry::tables()[$table];
        $target = $this->em->getClassMetadata($class)->getAssociationTargetClass($class::referenceAssociation($kind));
        // Scalar hydration checks the supplied writer, without accepting an ORM
        // reference proxy or invoking public-model read callbacks.
        return $this->em->createQueryBuilder()
            ->select('subject.id')
            ->from($target, 'subject')
            ->where('subject.id = :id')
            ->setParameter('id', $values[$selection['column']], Types::BIGINT)
            ->getQuery()
            ->getOneOrNullResult() !== null;
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
        $query = $this->em->createQueryBuilder()
            ->select('r')
            ->from($class, 'r');
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
            $query->andWhere('IDENTITY(a.entities) IN (:entities)')
                ->setParameter('entities', $entities);
        }
        $query->orderBy('r.itemtype')
            ->addOrderBy('r.items_id')
            ->addOrderBy('r.id');
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
                throw new LogicException('Returning components to stock requires an optional owning subject.');
            }
            if (!isset($reference['selections'][$assetType])) {
                return 0;
            }
            $association = $class::referenceAssociation($assetType);
            return $this->em->createQueryBuilder()
                ->update($class, 'r')
                ->set('r.' . $association, 'NULL')
                ->set('r.itemtype', 'NULL')
                ->where('r.itemtype = :type')
                ->setParameter('type', $assetType, Types::STRING)
                ->andWhere('IDENTITY(r.' . $association . ') = :asset')
                ->setParameter('asset', $asset, Types::BIGINT)
                ->getQuery()
                ->execute();
        }
        return $this->em->createQueryBuilder()
            ->update(EntityRegistry::tables()[$table], 'r')
            ->set('r.items_id', ':stock')
            ->setParameter('stock', 0, Types::INTEGER)
            ->set('r.itemtype', ':empty')
            ->setParameter('empty', '', Types::STRING)
            ->where('r.itemtype = :type')
            ->setParameter('type', $assetType, Types::STRING)
            ->andWhere('r.items_id = :asset')
            ->setParameter('asset', $asset, Types::INTEGER)
            ->getQuery()
            ->execute();
    }

    /** Complete stock candidates for the device's actual specificities, including legacy deleted stock. */
    public function stock(string $table, string $deviceColumn, int $device): array
    {
        $class = EntityRegistry::tables()[$table];
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference !== null && !isset($reference['empty_value'])) {
            throw new LogicException('A required component subject has no stock state.');
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
        $query = $this->em->createQueryBuilder()
            ->select('r.itemtype AS kind', $identity . ' AS asset')
            ->from($class, 'r');
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
        if (is_a($class, LegacyInput::class, true) && method_exists($class, 'withReference')) {
            $values = $class::withReference($values, $kind, $asset);
        }
        (new RecordWriter($this->em))->update($table, $binding, $values);
        return true;
    }
}
