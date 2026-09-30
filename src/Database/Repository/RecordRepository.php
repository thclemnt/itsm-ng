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
        $metadata = $this->em->getClassMetadata(EntityRegistry::TABLES[$table]);
        $field = $metadata->getFieldName($column);
        foreach ($metadata->associationMappings as $associationField => $mapping) {
            if ($mapping->joinColumns[0]->name === $column) {
                $field = $associationField;
            }
        }
        $record = $this->em->getRepository($metadata->name)->findOneBy([$field => $id]);
        return $record === null ? null : $this->toRow($record);
    }

    /** Select complete mapped records with bound criteria and database-side limits. */
    public function matching(string $table, array $criteria = [], array|string $order = [], ?int $limit = null, int $offset = 0, bool $legacyValues = true): array
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::TABLES[$table]);
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
        $metadata = $this->em->getClassMetadata(EntityRegistry::TABLES[$table]);
        $query = $this->em->createQueryBuilder()->select('COUNT(r.id)')->from($metadata->name, 'r');
        $query->where((new \itsmng\Database\RecordCriteria($query, $metadata, $legacyValues))->where($criteria));
        return (int)$query->getQuery()->getSingleScalarResult();
    }

    /** Snapshot identifiers before lifecycle hooks mutate the selected relationships. */
    public function identifiers(string $table, string $column, array $criteria, array|string $order = []): array
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::TABLES[$table]);
        $query = $this->em->createQueryBuilder()->from($metadata->name, 'r');
        $compiler = new \itsmng\Database\RecordCriteria($query, $metadata);
        $query->select($compiler->column($column) . ' AS record_id')->where($compiler->where($criteria));
        $compiler->order($order);
        return array_map('intval', array_column($query->getQuery()->getScalarResult(), 'record_id'));
    }

    public function toRow(object $record): array
    {
        $metadata = $this->em->getClassMetadata($record::class);
        $row = [];
        foreach ($metadata->fieldMappings as $property => $mapping) {
            $value = $record->$property;
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }
            if ($value !== null) {
                $value = match ($mapping->type) {
                    'boolean' => (int)$value,
                    'bigint' => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : $value,
                    'date' => $value->format('Y-m-d'),
                    'datetime', 'datetimetz' => $value->format('Y-m-d H:i:s'),
                    'time' => $value->format('H:i:s'),
                    'json' => json_encode($value, JSON_THROW_ON_ERROR),
                    default => $value,
                };
            }
            $row[$mapping->columnName] = $value;
        }
        foreach ($metadata->associationMappings as $property => $mapping) {
            $related = $record->$property;
            $row[$mapping->joinColumns[0]->name] = $related === null ? null : $this->em->getUnitOfWork()->getEntityIdentifier($related)['id'];
        }
        return $row;
    }
}
