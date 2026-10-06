<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\EntityRegistry;

/** ORM record access with the legacy model's scalar row contract at its boundary. */
final class RecordRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function find(string $table, string $column, int $id): ?array
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
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
    public function scalarRow(string $recordClass, int $id): ?array
    {
        $metadata = $this->em->getClassMetadata($recordClass);
        $identifier = $metadata->getSingleIdentifierFieldName();
        if (!$metadata->hasField($identifier)) {
            throw new \LogicException('Scalar record reads require a scalar identifier');
        }
        $query = $this->em->createQueryBuilder()->from($recordClass, 'r')
            ->where('r.' . $identifier . ' = :id')
            ->setParameter('id', $id, $metadata->getTypeOfField($identifier));
        $columns = [];
        foreach ($metadata->fieldMappings as $property => $mapping) {
            $query->addSelect('r.' . $property . ' AS value' . count($columns));
            $columns[] = [$mapping->columnName, $mapping->type, false];
        }
        foreach ($metadata->associationMappings as $property => $mapping) {
            if (!$mapping->isToOneOwningSide()) {
                continue;
            }
            if (count($mapping->joinColumns) !== 1) {
                throw new \LogicException('Scalar record reads require single-column owning references');
            }
            $target = $this->em->getClassMetadata($mapping->targetEntity);
            $targetId = $target->getSingleIdentifierFieldName();
            if (!$target->hasField($targetId) || $target->getColumnName($targetId) !== $mapping->joinColumns[0]->referencedColumnName) {
                throw new \LogicException('Scalar record references must target a scalar identifier');
            }
            $query->addSelect('IDENTITY(r.' . $property . ') AS value' . count($columns));
            $columns[] = [$mapping->joinColumns[0]->name, $target->getTypeOfField($targetId), true];
        }
        // Unlike HYDRATE_SCALAR, scalar-only array hydration applies DBAL types
        // (including temporal values and enums), without loading any entities.
        $values = $query->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
        if ($values === null) {
            return null;
        }
        $row = [];
        foreach ($columns as $index => [$column, $type, $reference]) {
            $value = $values['value' . $index];
            if ($reference) {
                // IDENTITY is an untyped DQL function; use the referenced ID's type.
                $value = \Doctrine\DBAL\Types\Type::getType($type)->convertToPHPValue(
                    $value, $this->em->getConnection()->getDatabasePlatform()
                );
            }
            $row[$column] = self::legacyScalarValue($value, $type);
        }
        return $row;
    }

    /** Select complete mapped records with bound criteria and database-side limits. */
    public function matching(string $table, array $criteria = [], array|string $order = [], ?int $limit = null, int $offset = 0, bool $legacyValues = true): array
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
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $this->toRow($record);
            $this->em->detach($record);
        }
        return $rows;
    }

    public function countMatching(string $table, array $criteria, bool $legacyValues = true): int
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        $query = $this->em->createQueryBuilder()->select('COUNT(r.id)')->from($metadata->name, 'r');
        $query->where((new \itsmng\Database\RecordCriteria($query, $metadata, $legacyValues))->where($criteria));
        return (int)$query->getQuery()->getSingleScalarResult();
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
