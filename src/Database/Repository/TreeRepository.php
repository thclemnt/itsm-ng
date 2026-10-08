<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use itsmng\Database\EntityRegistry;
use itsmng\Database\LegacyValues;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\ReadQueryOwner;
use itsmng\Database\RecordCriteria;

/** Tree projections and derived caches; model hooks remain responsible for reparenting. */
final class TreeRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function rows(string $table, array $fields, array $criteria, array|string $order = [], ?ReadQueryOwner $operation = null): array
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        $query = $this->em->createQueryBuilder()
            ->from($metadata->name, 'r');
        $compiler = new RecordCriteria($query, $metadata);
        foreach ($fields as $field) {
            // Validate identifiers through metadata before using them as result aliases.
            $column = $compiler->column($field);
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $field)) {
                throw new InvalidArgumentException('Tree projections require physical column names');
            }
            $query->addSelect($column . ' AS ' . $field);
        }
        $query->where($compiler->where($criteria));
        $compiler->order($order);
        $compiled = $query->getQuery();
        $operation?->prepareQuery($compiled, $metadata);
        return $compiled->getScalarResult();
    }

    /** Private-owner ID projection; null means the ordinary criteria path is required. */
    public function pointRows(string $table, array $fields, array $criteria): ?array
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        if (!$metadata->isInheritanceTypeNone() || count($metadata->identifier) !== 1
            || !$metadata->hasField($metadata->identifier[0]) || !$fields) {
            return null;
        }
        $identifier = $metadata->identifier[0];
        $column = $metadata->getColumnName($identifier);
        if (array_keys($criteria) !== [$column]) {
            return null;
        }
        $selection = self::pointIds($criteria[$column]);
        if ($selection === null) {
            return null;
        }
        [$ids, $list] = $selection;
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $query = $connection->createQueryBuilder()
            ->from($quote->getTableName($metadata, $platform), 'r');
        foreach ($fields as $field) {
            if (!is_string($field) || !preg_match('/^[a-zA-Z0-9_]+$/D', $field)) {
                return null;
            }
            $property = $metadata->getFieldName($field);
            if ($metadata->hasField($property)) {
                $expression = 'r.' . $quote->getColumnName($property, $metadata, $platform);
                $type = Type::getType($metadata->getTypeOfField($property));
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
        $parameters = self::pointParameters($query, $platform, $ids, $list, $typeName);
        $query->where('r.' . $quote->getColumnName($identifier, $metadata, $platform)
            . ($list ? ' IN (' . implode(', ', $parameters) . ')' : ' = ' . $parameters[0]));
        // ORM scalar aliases intentionally preserve native DBAL values too: JSON
        // caches remain strings and no entity hydration/PHP type conversion runs.
        return $query->executeQuery()
            ->fetchAllAssociative();
    }

    /** @internal A bounded cache/parent read on the captured core connection. */
    public static function projectedPointRows(Connection $connection, string $table, array $mapping, array $fields, array $criteria): ?array
    {
        $identifier = $mapping['identifier'];
        if (!$fields || array_keys($criteria) !== [$identifier]) {
            return null;
        }
        $selection = self::pointIds($criteria[$identifier]);
        if ($selection === null) {
            return null;
        }
        foreach ($fields as $field) {
            if (!is_string($field) || !preg_match('/^[a-zA-Z0-9_]+$/D', $field)
                || !isset($mapping['fields'][$field])
                || ($mapping['fields'][$field][2] === null && EntityRegistry::hasPolicy($table, $field, ReferenceKind::Inherited))) {
                return null;
            }
        }
        [$ids, $list] = $selection;
        $platform = $connection->getDatabasePlatform();
        $quote = static fn (array $name): string => $name[1] ? $platform->quoteSingleIdentifier($name[0]) : $name[0];
        $query = $connection->createQueryBuilder()
            ->from($quote($mapping['table']), 'r');
        foreach ($fields as $field) {
            $selection = $mapping['fields'][$field];
            $expression = 'r.' . $quote($selection);
            if ($selection[2] !== null) {
                $expression = Type::getType($selection[2])->convertToPHPValueSQL($expression, $platform);
            } elseif (EntityRegistry::hasPolicy($table, $field, ReferenceKind::RootParent)) {
                $expression = 'COALESCE(' . $expression . ', -1)';
            }
            $query->addSelect($expression . ' AS ' . $connection->quoteIdentifier($field));
        }
        $typeName = $mapping['fields'][$identifier][2];
        $parameters = self::pointParameters($query, $platform, $ids, $list, $typeName);
        $query->where('r.' . $quote($mapping['fields'][$identifier])
            . ($list ? ' IN (' . implode(', ', $parameters) . ')' : ' = ' . $parameters[0]));
        // Keep native scalar aliases, including raw JSON strings and nullable parents.
        return $query->executeQuery()
            ->fetchAllAssociative();
    }

    private static function pointIds(mixed $ids): ?array
    {
        $list = is_array($ids);
        if ($list) {
            // Only flat ID selections; operators and empty IN retain RecordCriteria semantics.
            if (!$ids) {
                return null;
            }
            foreach ($ids as $id) {
                if ($id !== null && !is_int($id)
                    && !(is_string($id) && (is_numeric($id) || $id === 'null' || $id === 'NULL'))) {
                    return null;
                }
            }
            $ids = array_values($ids);
        } elseif (!is_scalar($ids) || (is_string($ids) && strtolower($ids) === 'null')) {
            return null;
        } else {
            $ids = [$ids];
        }
        return [$ids, $list];
    }

    private static function pointParameters(QueryBuilder $query, AbstractPlatform $platform, array $ids, bool $list, string $typeName): array
    {
        $type = Type::getType($typeName);
        $parameters = [];
        foreach ($ids as $index => $id) {
            $value = LegacyValues::decode($id);
            if ($value !== null) {
                $value = match ($typeName) {
                    Types::BOOLEAN => (bool)(int)$value,
                    Types::INTEGER, Types::SMALLINT => (int)$value,
                    Types::FLOAT => (float)$value,
                    default => (string)$value,
                };
            }
            $name = $list ? 'id' . $index : 'id';
            // RecordCriteria converts each typed IN parameter, including SQL type
            // overrides. ArrayParameterType would bypass those conversions.
            $parameters[] = $type->convertToDatabaseValueSQL(':' . $name, $platform);
            $query->setParameter($name, $value, $typeName);
        }
        return $parameters;
    }

    /** Raw values only. These fields must not trigger recursive lifecycle hooks. */
    public function updateDerived(string $table, array $ids, array $values): void
    {
        if (!$ids || !$values) {
            return;
        }
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        $query = $this->em->createQueryBuilder()
            ->update($metadata->name, 'r');
        foreach ($values as $field => $value) {
            if (!in_array($field, ['completename', 'level', 'ancestors_cache', 'sons_cache'], true) || !$metadata->hasField($field)) {
                throw new InvalidArgumentException('Unsupported derived tree field: ' . $field);
            }
            $query->set('r.' . $field, ':' . $field)
                ->setParameter($field, $value, $metadata->getTypeOfField($field));
        }
        $query->where('r.id IN (:ids)')
            ->setParameter('ids', array_map('intval', array_values($ids)), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
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
            $this->em->createQueryBuilder()
                ->update($metadata->name, 'r')
                ->set('r.' . $field, ':parent')
                ->where('r.id IN (:ids)')
                ->setParameter('parent', $parent, Types::INTEGER)
                ->setParameter('ids', array_map('intval', array_values($ids)), ArrayParameterType::INTEGER)
                ->getQuery()
                ->execute();
            return;
        }
        throw new InvalidArgumentException('Implicit parent must be a mapped self association');
    }
}
