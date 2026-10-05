<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Entity;
use itsmng\Database\EntityConfigurationReferences;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

/** Read entity settings through mapped records, keeping the public scalar API. */
final class EntityConfigurationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function nextIdentifier(): int
    {
        return 1 + (int)$this->em->createQueryBuilder()->select('MAX(e.id)')->from(Entity::class, 'e')->getQuery()->getSingleScalarResult();
    }

    public function uniqueIdentifier(string $field, mixed $value): int
    {
        $rows = (new RecordRepository($this->em))->matching('glpi_entities', [$field => $value], limit: 2);
        return count($rows) === 1 ? (int)$rows[0]['id'] : -1;
    }

    public function notificationValues(string $field): array
    {
        $query = $this->em->createQueryBuilder()->from(Entity::class, 'r');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(Entity::class));
        $query->select('r.id AS entity', 'IDENTITY(r.parent) AS parent', $compiler->column($field) . ' AS value', 'CASE WHEN r.id = 0 THEN 0 ELSE 1 END AS HIDDEN root_order')
            ->orderBy('root_order')->addOrderBy('r.level')->addOrderBy('r.id');
        $values = [];
        foreach ($query->getQuery()->getScalarResult() as $row) {
            if (($row['value'] === null || $row['value'] == \Entity::CONFIG_PARENT) && isset($values[$row['parent']])) {
                $values[$row['entity']] = $values[$row['parent']];
            } elseif ($row['value'] > 0) {
                $values[$row['entity']] = $row['value'];
            }
        }
        return $values;
    }

    public function usedConfiguration(string $reference, int $entity, string $valueField, mixed $default): mixed
    {
        if ($entity < 0) {
            return $default;
        }
        $metadata = $this->em->getClassMetadata(Entity::class);
        $query = $this->em->createQueryBuilder()->select('IDENTITY(r.parent) AS parent_id')->from(Entity::class, 'r')
            ->where('r.id = :entity');
        $compiler = new RecordCriteria($query, $metadata, false);
        $columns = [$reference, $valueField];
        $references = EntityConfigurationReferences::fields();
        foreach (array_unique($columns) as $column) {
            if (isset($references[$column])) {
                $columns[] = $references[$column]->policy->modeProperty;
            }
        }
        // Unknown reference/value names retain the existing missing-field/default behavior.
        $columns = array_values(array_intersect(array_unique($columns), EntityRegistry::columnNames('glpi_entities')));
        foreach ($columns as $index => $column) {
            $query->addSelect($compiler->column($column) . ' AS setting' . $index);
        }
        $seen = [];
        while ($entity >= 0) {
            if (isset($seen[$entity])) {
                throw new \RuntimeException('Cyclic entity configuration inheritance');
            }
            $seen[$entity] = true;
            $result = $query->setParameter('entity', $entity, Types::INTEGER)->getQuery()->getOneOrNullResult();
            if ($result === null) {
                return $default;
            }
            $row = [];
            foreach ($columns as $index => $column) {
                $value = $result['setting' . $index];
                $mapping = $metadata->fieldMappings[$metadata->getFieldName($column)] ?? null;
                if ($value instanceof \BackedEnum) {
                    $value = $value->value;
                }
                if ($value !== null) {
                    // Preserve the model row's scalar types; owning references are identifiers.
                    $value = match ($mapping?->type ?? Types::BIGINT) {
                        Types::BOOLEAN => (int)$value,
                        Types::BIGINT => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : $value,
                        Types::DATE_MUTABLE => $value->format('Y-m-d'),
                        Types::DATETIME_MUTABLE, Types::DATETIMETZ_MUTABLE => $value->format('Y-m-d H:i:s'),
                        Types::TIME_MUTABLE => $value->format('H:i:s'),
                        Types::JSON => json_encode($value, JSON_THROW_ON_ERROR),
                        default => $value,
                    };
                }
                $row[$column] = $value;
            }
            $row = EntityConfigurationReferences::legacyRow($row);
            if (isset($row[$reference]) && (is_numeric($default) ? $row[$reference] != \Entity::CONFIG_PARENT : (bool)$row[$reference])) {
                return array_key_exists($valueField, $row) ? $row[$valueField] : $default;
            }
            if ($entity === 0) {
                return $default;
            }
            $entity = $result['parent_id'] === null ? -1 : (int)$result['parent_id'];
        }
        return $default;
    }
}
