<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use itsmng\Database\Mapping\ReferenceKind;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

/** Tree projections and derived caches; model hooks remain responsible for reparenting. */
final class TreeRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function rows(string $table, array $fields, array $criteria, array|string $order = [], ?\itsmng\Database\ReadQueryOwner $operation = null): array
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        $query = $this->em->createQueryBuilder()->from($metadata->name, 'r');
        $compiler = new RecordCriteria($query, $metadata);
        foreach ($fields as $field) {
            // Validate identifiers through metadata before using them as result aliases.
            $column = $compiler->column($field);
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $field)) {
                throw new \InvalidArgumentException('Tree projections require physical column names');
            }
            $query->addSelect($column . ' AS ' . $field);
        }
        $query->where($compiler->where($criteria));
        $compiler->order($order);
        $compiled = $query->getQuery();
        $operation?->prepareQuery($compiled, $metadata);
        return $compiled->getScalarResult();
    }

    /** Private-owner point projection; null means the ordinary criteria path is required. */
    public function pointRows(string $table, array $fields, array $criteria): ?array
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        if (!$metadata->isInheritanceTypeNone() || count($metadata->identifier) !== 1
            || !$metadata->hasField($metadata->identifier[0]) || !$fields) {
            return null;
        }
        $identifier = $metadata->identifier[0];
        $column = $metadata->getColumnName($identifier);
        if (array_keys($criteria) !== [$column] || !is_scalar($criteria[$column])
            || (is_string($criteria[$column]) && strtolower($criteria[$column]) === 'null')) {
            return null;
        }
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $query = $connection->createQueryBuilder()->from($quote->getTableName($metadata, $platform), 'r');
        foreach ($fields as $field) {
            if (!is_string($field) || !preg_match('/^[a-zA-Z0-9_]+$/D', $field)) {
                return null;
            }
            $property = $metadata->getFieldName($field);
            if ($metadata->hasField($property)) {
                $expression = 'r.' . $quote->getColumnName($property, $metadata, $platform);
                $type = \Doctrine\DBAL\Types\Type::getType($metadata->getTypeOfField($property));
                $expression = $type->convertToPHPValueSQL($expression, $platform);
            } else {
                $expression = null;
                foreach ($metadata->associationMappings as $mapping) {
                    if (!$mapping->isToOneOwningSide() || count($mapping->joinColumns) !== 1
                        || $mapping->joinColumns[0]->name !== $field) {
                        continue;
                    }
                    // Inherited settings have a domain CASE expression; retain its existing compiler.
                    if (EntityRegistry::hasPolicy($table, $field, ReferenceKind::Inherited)) {
                        return null;
                    }
                    $expression = 'r.' . $quote->getJoinColumnName($mapping->joinColumns[0], $metadata, $platform);
                    if (EntityRegistry::hasPolicy($table, $field, ReferenceKind::RootParent)) {
                        $expression = 'COALESCE(' . $expression . ', -1)';
                    }
                    break;
                }
                if ($expression === null) {
                    return null;
                }
            }
            $query->addSelect($expression . ' AS ' . $connection->quoteIdentifier($field));
        }
        $typeName = $metadata->getTypeOfField($identifier);
        $value = \itsmng\Database\LegacyValues::decode($criteria[$column]);
        $value = match ($typeName) {
            Types::BOOLEAN => (bool)(int)$value,
            Types::INTEGER, Types::SMALLINT => (int)$value,
            Types::FLOAT => (float)$value,
            default => (string)$value,
        };
        $parameter = \Doctrine\DBAL\Types\Type::getType($typeName)->convertToDatabaseValueSQL(':id', $platform);
        $query->where('r.' . $quote->getColumnName($identifier, $metadata, $platform) . ' = ' . $parameter)
            ->setParameter('id', $value, $typeName);
        // ORM scalar aliases intentionally preserve native DBAL values too: JSON
        // caches remain strings and no entity hydration/PHP type conversion runs.
        return $query->executeQuery()->fetchAllAssociative();
    }

    /** Raw values only. These fields must not trigger recursive lifecycle hooks. */
    public function updateDerived(string $table, array $ids, array $values): void
    {
        if (!$ids || !$values) {
            return;
        }
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        $query = $this->em->createQueryBuilder()->update($metadata->name, 'r');
        foreach ($values as $field => $value) {
            if (!in_array($field, ['completename', 'level', 'ancestors_cache', 'sons_cache'], true) || !$metadata->hasField($field)) {
                throw new \InvalidArgumentException('Unsupported derived tree field: ' . $field);
            }
            $query->set('r.' . $field, ':' . $field)->setParameter($field, $value, $metadata->getTypeOfField($field));
        }
        $query->where('r.id IN (:ids)')->setParameter('ids', array_map('intval', array_values($ids)), ArrayParameterType::INTEGER)
            ->getQuery()->execute();
    }

    /** Persist an implicit tree's chosen parent without recursively selecting it again. */
    public function reparent(string $table, string $column, array $ids, ?int $parent): void
    {
        if (!$ids) {
            return;
        }
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        foreach ($metadata->associationMappings as $field => $mapping) {
            if ($mapping->joinColumns[0]->name !== $column || $mapping->targetEntity !== $metadata->name) {
                continue;
            }
            if ($parent === 0 && EntityRegistry::hasPolicy($table, $column, ReferenceKind::EmptySelection)) {
                $parent = null;
            }
            $this->em->createQueryBuilder()->update($metadata->name, 'r')->set('r.' . $field, ':parent')
                ->where('r.id IN (:ids)')->setParameter('parent', $parent, Types::INTEGER)
                ->setParameter('ids', array_map('intval', array_values($ids)), ArrayParameterType::INTEGER)->getQuery()->execute();
            return;
        }
        throw new \InvalidArgumentException('Implicit parent must be a mapped self association');
    }
}
