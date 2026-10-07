<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use itsmng\Database\EntityRegistry;
use itsmng\Database\MappedRowProjection;

/** ORM record access with the legacy model's scalar row contract at its boundary. */
final class RecordRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function find(string $table, string $column, int $id, LockMode $lockMode = LockMode::NONE): ?array
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        if ($lockMode === LockMode::PESSIMISTIC_WRITE) {
            $query = $this->em->createQueryBuilder()->select('r')->from($metadata->name, 'r');
            $query->where((new \itsmng\Database\RecordCriteria($query, $metadata, false))->where([$column => $id]));
            $record = $query->getQuery()->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)
                ->setLockMode($lockMode)->getOneOrNullResult();
            return $record === null ? null : $this->toRow($record);
        }
        if ($lockMode !== LockMode::NONE) {
            throw new \InvalidArgumentException('Mapped model loads support ordinary or current write-lock reads.');
        }
        if (count($metadata->identifier) === 1
            && !$metadata->hasLifecycleCallbacks(Events::postLoad)
            && empty($metadata->entityListeners[Events::postLoad])
            && !$this->em->getEventManager()->hasListeners(Events::postLoad)) {
            $identifier = $metadata->getSingleIdentifierFieldName();
            if ($metadata->hasField($identifier) && $metadata->getColumnName($identifier) === $column) {
                return $this->scalarRow($metadata->name, $id);
            }
        }
        $field = $metadata->getFieldName($column);
        foreach ($metadata->associationMappings as $associationField => $mapping) {
            if (!$mapping->isToOneOwningSide()) {
                continue;
            }
            if ($mapping->joinColumns[0]->name === $column) {
                $field = $associationField;
            }
        }
        $record = $this->em->getRepository($metadata->name)->findOneBy([$field => $id]);
        return $record === null ? null : $this->toRow($record);
    }

    /** Complete legacy row without creating managed records or association proxies. */
    public function scalarRow(string $recordClass, int $id, ?\Psr\Cache\CacheItemPoolInterface $queryCache = null, ?array $defaultIdentifiers = null, ?\itsmng\Database\RecordReadOperation $operation = null): ?array
    {
        $metadata = $this->em->getClassMetadata($recordClass);
        $identifier = $metadata->getSingleIdentifierFieldName();
        if (!$metadata->hasField($identifier)) {
            throw new \LogicException('Scalar record reads require a scalar identifier');
        }
        $query = $this->em->createQueryBuilder()->from($recordClass, 'r')
            ->where('r.' . $identifier . ' = :id')
            ->setParameter('id', $id, $metadata->getTypeOfField($identifier));
        $projection = new MappedRowProjection($this->em, $metadata, $defaultIdentifiers);
        $projection->select($query);
        // Scalar-only array hydration applies DBAL types without loading entities.
        $compiled = $query->getQuery();
        if ($queryCache !== null) {
            $compiled->setQueryCache($queryCache);
        }
        $operation?->prepareQuery($compiled, $metadata);
        $values = $compiled->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
        return $values === null ? null : $projection->toRow($values);
    }

    /** Select complete mapped records with bound criteria and database-side limits. */
    public function matching(string $table, array $criteria = [], array|string $order = [], ?int $limit = null, int $offset = 0, bool $legacyValues = true, ?array $defaultIdentifiers = null, ?\itsmng\Database\RecordReadOperation $operation = null): array
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        $query = $this->em->createQueryBuilder()->select('r')->from($metadata->name, 'r');
        $compiler = new \itsmng\Database\RecordCriteria($query, $metadata, $legacyValues);
        $query->where($compiler->where($criteria));
        $compiler->order($order);
        if ($limit !== null && $limit > 0) {
            $query->setMaxResults($limit);
        }
        $query->setFirstResult(max(0, $offset));
        $rows = [];
        // An existing identity map or post-load dispatch retains ordinary ORM semantics.
        if ($this->em->getUnitOfWork()->size() === 0
            && count($metadata->identifier) === 1
            && $metadata->hasField($metadata->getSingleIdentifierFieldName())
            && !$metadata->hasLifecycleCallbacks(Events::postLoad)
            && empty($metadata->entityListeners[Events::postLoad])
            && !$this->em->getEventManager()->hasListeners(Events::postLoad)) {
            $projection = new MappedRowProjection($this->em, $metadata, $defaultIdentifiers);
            $projection->select($query);
            $compiled = $query->getQuery();
            $operation?->prepareQuery($compiled, $metadata);
            foreach ($compiled->toIterable([], \Doctrine\ORM\Query::HYDRATE_ARRAY) as $values) {
                $rows[] = $projection->toRow($values);
            }
            return $rows;
        }
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $this->toRow($record);
            $this->em->detach($record);
        }
        return $rows;
    }

    public function countMatching(string $table, array $criteria, bool $legacyValues = true, ?\itsmng\Database\RecordReadOperation $operation = null): int
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        $query = $this->em->createQueryBuilder()->select('COUNT(r.id)')->from($metadata->name, 'r');
        $query->where((new \itsmng\Database\RecordCriteria($query, $metadata, $legacyValues))->where($criteria));
        $compiled = $query->getQuery();
        $operation?->prepareQuery($compiled, $metadata);
        return (int)$compiled->getSingleScalarResult();
    }

    /** Scalar distinct values retain the requested column name at the model boundary. */
    public function distinctValues(string $table, string $column, array $criteria, array|string $order = []): array
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        $query = $this->em->createQueryBuilder()->from($metadata->name, 'r');
        $compiler = new \itsmng\Database\RecordCriteria($query, $metadata);
        $query->select('DISTINCT ' . $compiler->column($column) . ' AS value')->where($compiler->where($criteria));
        $compiler->order($order);
        return array_map(static fn (array $row): array => [$column => $row['value']], $query->getQuery()->getScalarResult());
    }

    /** Snapshot identifiers before lifecycle hooks mutate the selected relationships. */
    public function identifiers(string $table, string $column, array $criteria, array|string $order = []): array
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        $query = $this->em->createQueryBuilder()->from($metadata->name, 'r');
        $compiler = new \itsmng\Database\RecordCriteria($query, $metadata);
        $query->select($compiler->column($column) . ' AS record_id')->where($compiler->where($criteria));
        $compiler->order($order);
        return array_map('intval', array_column($query->getQuery()->getScalarResult(), 'record_id'));
    }

    /** Scalar conversion shared by complete model rows and domain projections. */
    public static function legacyScalarValue(mixed $value, string $type): mixed
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'boolean' => (int)$value,
            'bigint' => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : $value,
            'date' => $value->format('Y-m-d'),
            'datetime', 'datetimetz' => $value->format('Y-m-d H:i:s'),
            'time' => $value->format('H:i:s'),
            'json' => json_encode($value, JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    public function toRow(object $record): array
    {
        $metadata = $this->em->getClassMetadata($record::class);
        $row = [];
        foreach ($metadata->fieldMappings as $property => $mapping) {
            $row[$mapping->columnName] = self::legacyScalarValue($record->$property, $mapping->type);
        }
        foreach ($metadata->associationMappings as $property => $mapping) {
            if (!$mapping->isToOneOwningSide()) {
                continue;
            }
            $related = $record->$property;
            $row[$mapping->joinColumns[0]->name] = $related === null ? null : $this->em->getUnitOfWork()->getEntityIdentifier($related)['id'];
        }
        return $row;
    }
}
