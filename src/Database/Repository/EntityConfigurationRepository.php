<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\NoResultException;
use Entity as LegacyEntity;
use itsmng\Database\Entity\Entity;
use itsmng\Database\EntityConfigurationReferences;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Orm;
use itsmng\Database\RecordCriteria;
use ReflectionEnum;
use RuntimeException;

/** Read entity settings through mapped records, keeping the public scalar API. */
final class EntityConfigurationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function nextIdentifier(): int
    {
        return 1 + (int)$this->em->createQueryBuilder()
            ->select('MAX(e.id)')
            ->from(Entity::class, 'e')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function uniqueIdentifier(string $field, mixed $value): int
    {
        $query = $this->em->createQueryBuilder()
            ->select('r.id')
            ->from(Entity::class, 'r');
        $criteria = new RecordCriteria($query, $this->em->getClassMetadata(Entity::class));
        $ids = $query->where($criteria->where([$field => $value]))
            ->setMaxResults(2)
            ->getQuery()
            ->getSingleColumnResult();
        return count($ids) === 1 ? (int)$ids[0] : -1;
    }

    public function notificationValues(string $field): array
    {
        $query = $this->em->createQueryBuilder()
            ->from(Entity::class, 'r');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(Entity::class));
        $query->select(
            'r.id AS entity',
            'IDENTITY(r.parent) AS parent',
            $compiler->column($field) . ' AS value',
            'CASE WHEN r.id = 0 THEN 0 ELSE 1 END AS HIDDEN root_order'
        )
            ->orderBy('root_order')
            ->addOrderBy('r.level')
            ->addOrderBy('r.id');
        $values = [];
        foreach ($query->getQuery()->getScalarResult() as $row) {
            if (($row['value'] === null || $row['value'] == LegacyEntity::CONFIG_PARENT) && $row['parent'] !== null && isset($values[$row['parent']])) {
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
        $query = $this->em->createQueryBuilder()
            ->select('IDENTITY(r.parent) AS parent_id')
            ->from(Entity::class, 'r')
            ->where('r.id = :entity');
        $compiler = new RecordCriteria($query, $metadata, false);
        $columns = self::configurationColumns($reference, $valueField);
        foreach ($columns as $index => $column) {
            $query->addSelect($compiler->column($column) . ' AS setting' . $index);
        }
        return self::inheritConfiguration($reference, $entity, $valueField, $default, function (int $entity) use ($query, $columns, $metadata): ?array {
            $result = $query->setParameter('entity', $entity, Types::INTEGER)
                ->getQuery()
                ->getOneOrNullResult();
            if ($result === null) {
                return null;
            }
            $row = [];
            foreach ($columns as $index => $column) {
                $mapping = $metadata->fieldMappings[$metadata->getFieldName($column)] ?? null;
                $row[$column] = RecordRepository::legacyScalarValue($result['setting' . $index], $mapping?->type ?? Types::BIGINT);
            }
            return [$result['parent_id'], $row];
        });
    }

    /** Canonical application route; supplied managers retain usedConfiguration(). */
    public static function readUsedConfiguration(Connection $connection, string $reference, int $entity, string $valueField, mixed $default): mixed
    {
        if ($entity < 0) {
            return $default;
        }
        $platform = $connection->getDatabasePlatform();
        if (method_exists($connection, 'getEventManager')) {
            return (new self(Orm::forConnection($connection)))->usedConfiguration($reference, $entity, $valueField, $default);
        }
        $columns = self::configurationColumns($reference, $valueField);
        $types = EntityRegistry::fieldTypes('glpi_entities');
        $enums = EntityRegistry::fieldEnums('glpi_entities');
        return self::inheritConfiguration($reference, $entity, $valueField, $default, static function (int $entity) use ($connection, $platform, $columns, $types, $enums): ?array {
            try {
                // Resolve conversions for each fresh ancestor read, just as each ORM query does.
                $query = $connection->createQueryBuilder()
                    ->select($platform->quoteIdentifier('entities_id') . ' AS parent_id')
                    ->from($platform->quoteIdentifier('glpi_entities'));
                foreach ($columns as $index => $column) {
                    $expression = $platform->quoteIdentifier($column);
                    if (isset($types[$column])) {
                        $expression = Type::getType($types[$column])->convertToPHPValueSQL($expression, $platform);
                    }
                    $query->addSelect($expression . ' AS setting' . $index);
                }
                $query->where(
                    $platform->quoteIdentifier('id') . ' = '
                    . Type::getType(Types::INTEGER)->convertToDatabaseValueSQL(':entity', $platform)
                )
                    ->setParameter('entity', $entity, Types::INTEGER);
                $result = $query->executeQuery()->fetchAssociative();
                if ($result === false) {
                    return null;
                }
                $parent = Type::getType(Types::STRING)->convertToPHPValue($result['parent_id'], $platform);
                $row = [];
                foreach ($columns as $index => $column) {
                    // IDENTITY() is hydrated as an untyped string, without SQL conversion.
                    $value = Type::getType($types[$column] ?? Types::STRING)->convertToPHPValue($result['setting' . $index], $platform);
                    if ($value !== null && isset($enums[$column])) {
                        $enum = $enums[$column];
                        $integer = (new ReflectionEnum($enum))->getBackingType()->getName() === 'int';
                        $convert = static fn ($entry) => $enum::from($integer ? (int)$entry : $entry);
                        $value = is_array($value) ? array_map($convert, $value) : $convert($value);
                    }
                    $row[$column] = $value;
                }
            } catch (NoResultException) {
                // getOneOrNullResult() treats this query/hydration outcome as no row.
                return null;
            }
            foreach ($columns as $column) {
                $row[$column] = RecordRepository::legacyScalarValue($row[$column], $types[$column] ?? Types::BIGINT);
            }
            return [$parent, $row];
        });
    }

    private static function configurationColumns(string $reference, string $valueField): array
    {
        $columns = [$reference, $valueField];
        $references = EntityConfigurationReferences::fields();
        foreach (array_unique($columns) as $column) {
            if (isset($references[$column])) {
                $columns[] = $references[$column]->policy->modeProperty;
            }
        }
        // Unknown reference/value names retain the existing missing-field/default behavior.
        return array_values(array_intersect(array_unique($columns), EntityRegistry::columnNames('glpi_entities')));
    }

    /** One inheritance walk for both canonical DBAL and externally configured ORM readers. */
    private static function inheritConfiguration(string $reference, int $entity, string $valueField, mixed $default, callable $read): mixed
    {
        $seen = [];
        while ($entity >= 0) {
            if (isset($seen[$entity])) {
                throw new RuntimeException('Cyclic entity configuration inheritance');
            }
            $seen[$entity] = true;
            $result = $read($entity);
            if ($result === null) {
                return $default;
            }
            [$parent, $row] = $result;
            $row = EntityConfigurationReferences::legacyRow($row);
            if (isset($row[$reference]) && (is_numeric($default) ? $row[$reference] != LegacyEntity::CONFIG_PARENT : (bool)$row[$reference])) {
                return array_key_exists($valueField, $row) ? $row[$valueField] : $default;
            }
            if ($entity === 0) {
                return $default;
            }
            $entity = $parent === null ? -1 : (int)$parent;
        }
        return $default;
    }
}
