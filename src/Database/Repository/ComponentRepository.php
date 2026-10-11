<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
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
            if ($reference !== null && !isset($reference['selections'][$type]) && !isset($reference['fallback_column'])) {
                continue;
            }
            $subject = $this->subjectExpression($table, $type);
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

    /** @internal Compile one family for the caller-owned component total. */
    public function nativeCountQueryForAsset(string $table, string $type, int $id): ?QueryBuilder
    {
        $class = EntityRegistry::tables()[$table];
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference !== null && !isset($reference['selections'][$type]) && !isset($reference['fallback_column'])) {
            return null;
        }
        $metadata = $this->em->getClassMetadata($class);
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $column = static fn (string $field): string => 'r.' . $quote->getColumnName($field, $metadata, $platform);
        $from = static fn (): string => $quote->getTableName($metadata, $platform);
        if ($reference === null) {
            $subject = $column('items_id');
        } elseif (isset($reference['fallback_column'])) {
            $slots = [];
            foreach (array_keys($reference['selections']) as $kind) {
                $association = $metadata->associationMappings[$class::referenceAssociation($kind)];
                if (!$association->isToOneOwningSide() || count($association->joinColumns) !== 1) {
                    throw new LogicException('Component counts require a single owning subject reference.');
                }
                $slots[] = 'r.' . $quote->getJoinColumnName($association->joinColumns[0], $metadata, $platform);
            }
            $slots[] = $column($metadata->getFieldName($reference['fallback_column']));
            $subject = 'COALESCE(' . implode(', ', array_unique($slots)) . ', 0)';
        } else {
            $association = $metadata->associationMappings[$class::referenceAssociation($type)];
            if (!$association->isToOneOwningSide() || count($association->joinColumns) !== 1) {
                throw new LogicException('Component counts require a single owning subject reference.');
            }
            $subject = 'r.' . $quote->getJoinColumnName($association->joinColumns[0], $metadata, $platform);
        }
        return self::countQuery($connection, $platform, $column, $from, $subject, $type, $id);
    }

    /** @internal The admitted immutable projection needs only the selected DBAL connection. */
    public static function projectedCountQueryForAsset(Connection $connection, string $table, string $type, int $id, array $mapping): ?QueryBuilder
    {
        $class = EntityRegistry::tables()[$table];
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference !== null && !isset($reference['selections'][$type]) && !isset($reference['fallback_column'])) {
            return null;
        }
        $platform = $connection->getDatabasePlatform();
        $identifier = static fn (array $name): string => $name[1] ? $platform->quoteSingleIdentifier($name[0]) : $name[0];
        $column = static fn (string $field): string => 'r.' . $identifier($mapping['fields'][$field]);
        $from = static fn (): string => $identifier($mapping['table']);
        if ($reference === null) {
            $subject = $column('items_id');
        } elseif (isset($reference['fallback_column'])) {
            $slots = [];
            foreach (array_keys($reference['selections']) as $kind) {
                $join = $mapping['subjects'][$class::referenceAssociation($kind)] ?? null;
                if ($join === null) {
                    throw new LogicException('Open component counts require their owning subject projection.');
                }
                $slots[] = 'r.' . $identifier($join);
            }
            $slots[] = $column($reference['fallback_column']);
            $subject = 'COALESCE(' . implode(', ', array_unique($slots)) . ', 0)';
        } else {
            $join = $mapping['subjects'][$class::referenceAssociation($type)] ?? null;
            if ($join === null) {
                throw new LogicException('Component counts require a single owning subject reference.');
            }
            $subject = 'r.' . $identifier($join);
        }
        return self::countQuery($connection, $platform, $column, $from, $subject, $type, $id);
    }

    private static function countQuery(Connection $connection, AbstractPlatform $platform, callable $column, callable $from, string $subject, string $type, int $id): QueryBuilder
    {
        $asset = Type::getType(Types::BIGINT);
        $kind = Type::getType(Types::STRING);
        $deleted = Type::getType(Types::BOOLEAN);
        // COUNT's path and scalar hydration do not apply mapped SQL/PHP output converters.
        return $connection->createQueryBuilder()
            ->select('COUNT(' . $column('id') . ')')
            ->from($from(), 'r')
            ->where($subject . ' = ' . $asset->convertToDatabaseValueSQL('?', $platform))
            ->andWhere($column('itemtype') . ' = ' . $kind->convertToDatabaseValueSQL('?', $platform))
            ->andWhere($column('is_deleted') . ' = ' . $deleted->convertToDatabaseValueSQL('?', $platform))
            ->setParameter(0, $id, Types::BIGINT)
            ->setParameter(1, $type, Types::STRING)
            ->setParameter(2, false, Types::BOOLEAN);
    }

    /** A selected typed subject must exist; stock deliberately selects no subject. */
    public function hasSelectedSubject(string $table, array $values): bool
    {
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference === null) {
            return true;
        }
        $kind = $values[$reference['discriminator']] ?? null;
        $selection = is_string($kind) ? ($reference['selections'][$kind] ?? null) : null;
        if ($selection === null && isset($reference['fallback_column'])) {
            return true;
        }
        if ($kind === null && array_key_exists('empty_value', $reference)) {
            return true;
        }
        if ($selection !== null && isset($reference['fallback_column']) && ($values[$selection['column']] ?? null) === null
            && ($selection['empty_value'] ?? null) === 0) {
            return true;
        }
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
        if ($reference !== null && !isset($reference['fallback_column']) && ($assetType === '' || $assetType === null) && isset($reference['empty_value'])) {
            $assetType = null;
        } elseif ($reference !== null && !isset($reference['selections'][$assetType ?? '']) && !isset($reference['fallback_column'])) {
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
            if ($reference !== null && !isset($reference['fallback_column']) && isset($reference['selections'][$assetType])) {
                if (($reference['selections'][$assetType]['target'] ?? null) !== $assetTable) {
                    return [];
                }
                $query->innerJoin('r.' . $class::referenceAssociation($assetType), 'a');
            } else {
                $query->innerJoin(EntityRegistry::tables()[$assetTable], 'a', 'WITH', 'a.id = ' . $this->subjectExpression($table, $assetType));
            }
            $query->andWhere('IDENTITY(a.entities) IN (:entities)')
                ->setParameter('entities', $entities);
        }
        $query->orderBy('r.itemtype')
            ->addOrderBy('r.items_id')
            ->addOrderBy('r.id');
        return (new RecordRepository($this->em))->toRows($query->getQuery()->toIterable());
    }

    /** Returning components to stock deliberately does not run per-component update hooks. */
    public function detach(string $table, string $assetType, int $asset): int
    {
        $class = EntityRegistry::tables()[$table];
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference !== null && isset($reference['fallback_column'])) {
            $metadata = $this->em->getClassMetadata($class);
            $query = $this->em->createQueryBuilder()->update($class, 'r')
                ->set('r.' . $metadata->getFieldName($reference['fallback_column']), ':stock')
                ->setParameter('stock', 0, Types::BIGINT)
                ->set('r.itemtype', ':empty')->setParameter('empty', '', Types::STRING)
                ->where('r.itemtype = :type')->setParameter('type', $assetType, Types::STRING)
                ->andWhere($this->subjectExpression($table, $assetType) . ' = :asset')
                ->setParameter('asset', $asset, Types::BIGINT);
            foreach (array_keys($reference['selections']) as $kind) {
                $query->set('r.' . $class::referenceAssociation($kind), 'NULL');
            }
            return $query->getQuery()->execute();
        }
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
        $kind = $reference !== null && !isset($reference['fallback_column']) ? null : '';
        return (new RecordRepository($this->em))->matching($table, [$deviceColumn => $device, 'itemtype' => $kind], ['id']);
    }

    /** Assignment rows for a transfer; exclusion is of device owners, never binding IDs. */
    public function assigned(string $table, string $deviceColumn, string $kind, int $asset, array $excludedDevices): array
    {
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference !== null && !isset($reference['selections'][$kind]) && !isset($reference['fallback_column'])) {
            return [];
        }
        $identity = isset($reference['fallback_column']) ? 'items_id' : ($reference['selections'][$kind]['column'] ?? 'items_id');
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
        $identity = $reference !== null && !isset($reference['fallback_column']) && count($reference['selections']) === 1
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
    /** The declared exclusive slots retain the public kind comparison language. */
    private function subjectExpression(string $table, ?string $kind): string
    {
        $reference = EntityRegistry::discriminatedReferences($table)['items_id'] ?? null;
        if ($reference === null) {
            return 'r.items_id';
        }
        $class = EntityRegistry::tables()[$table];
        if (isset($reference['fallback_column'])) {
            $slots = [];
            foreach (array_keys($reference['selections']) as $selection) {
                $slots[] = 'IDENTITY(r.' . $class::referenceAssociation($selection) . ')';
            }
            $slots[] = 'r.' . $this->em->getClassMetadata($class)->getFieldName($reference['fallback_column']);
            // The existing ordinary kind comparison may match case aliases.
            // Slot exclusivity gives their exact stored identity without changing
            // that comparison to a new binary predicate.
            return 'COALESCE(' . implode(', ', array_unique($slots)) . ', 0)';
        }
        if ($kind !== null && isset($reference['selections'][$kind])) {
            return 'IDENTITY(r.' . $class::referenceAssociation($kind) . ')';
        }
        throw new LogicException('The component kind has no owning or opaque identity.');
    }
}
