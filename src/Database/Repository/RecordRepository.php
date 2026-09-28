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

    public function toRow(object $record): array
    {
        $metadata = $this->em->getClassMetadata($record::class);
        $row = [];
        foreach ($metadata->fieldMappings as $property => $mapping) {
            $value = $record->$property;
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
