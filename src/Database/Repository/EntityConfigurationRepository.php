<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Entity;
use itsmng\Database\EntityConfigurationReferences;
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
        $query->select('r.id AS entity', 'r.entities_id AS parent', $compiler->column($field) . ' AS value', 'CASE WHEN r.id = 0 THEN 0 ELSE 1 END AS HIDDEN root_order')
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
        $records = new RecordRepository($this->em);
        $seen = [];
        while ($entity >= 0) {
            if (isset($seen[$entity])) {
                throw new \RuntimeException('Cyclic entity configuration inheritance');
            }
            $seen[$entity] = true;
            $record = $this->em->find(Entity::class, $entity);
            if ($record === null) {
                return $default;
            }
            $row = EntityConfigurationReferences::legacyRow($records->toRow($record));
            if (isset($row[$reference]) && (is_numeric($default) ? $row[$reference] != \Entity::CONFIG_PARENT : (bool)$row[$reference])) {
                return array_key_exists($valueField, $row) ? $row[$valueField] : $default;
            }
            if ($entity === 0) {
                return $default;
            }
            $entity = (int)$record->entities_id;
        }
        return $default;
    }
}
