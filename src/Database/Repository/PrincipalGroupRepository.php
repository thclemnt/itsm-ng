<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use itsmng\Database\Entity\Group;
use itsmng\Database\Entity\GroupMembership;
use itsmng\Database\Entity\User;
use itsmng\Database\EntityRestriction;
use itsmng\Database\Mapping\AttributeDriver;

/** Current CalDAV relations; URI callbacks and authorization remain with Principal. */
final class PrincipalGroupRepository
{
    private ClassMetadata $group;
    private ClassMetadata $membership;
    private ClassMetadata $user;
    private DefaultQuoteStrategy $quote;

    public function __construct(private Connection $connection, ?EntityManager $manager)
    {
        $this->quote = new DefaultQuoteStrategy();
        // Custom physical readers use the same declarations without constructing
        // a custom EntityManager, dispatching events, or hydrating live records.
        $driver = $manager === null
            ? new AttributeDriver([], $connection->getDatabasePlatform()) : null;
        foreach (['group' => Group::class, 'membership' => GroupMembership::class, 'user' => User::class] as $property => $class) {
            $metadata = $manager?->getClassMetadata($class) ?? new ClassMetadata($class);
            if ($driver !== null) {
                $metadata->initializeReflection(new RuntimeReflectionService());
                $driver->loadMetadataForClass($class, $metadata);
            }
            $this->$property = $metadata;
        }
    }

    /** Group membership deliberately retains the existing child-group direction. */
    public function taskChildIds(int|string|null $parent, EntityRestriction $scope): array
    {
        $query = $this->taskGroups($scope);
        $this->selection(
            $query,
            $this->reference('g', $this->group, 'groups'),
            $parent,
            'parent',
            $this->group->getTypeOfField('id')
        );
        return $this->groupRows($query);
    }

    /** No user visibility flags or DISTINCT: equal names retain their multiplicity. */
    public function directMemberNames(int|string|null $group): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select($this->field('u', $this->user, 'name') . ' AS name')
            ->from($this->table($this->user), 'u')
            ->innerJoin(
                'u',
                $this->table($this->membership),
                'm',
                $this->field('u', $this->user, 'id') . ' = ' . $this->reference('m', $this->membership, 'users'),
            );
        $this->selection(
            $query,
            $this->reference('m', $this->membership, 'groups'),
            $group,
            'group',
            $this->group->getTypeOfField('id')
        );
        return $query->executeQuery()->fetchAllAssociative();
    }

    /** Keep the scalar lookup: an ambiguous username fails instead of granting a union. */
    public function taskMembershipIdsForUsername(?string $username, EntityRestriction $scope): array
    {
        $query = $this->taskGroups($scope);
        $user = $this->connection->createQueryBuilder()
            ->select($this->field('u', $this->user, 'id'))
            ->from($this->table($this->user), 'u');
        $this->selection(
            $user,
            $this->field('u', $this->user, 'name'),
            $username,
            'username',
            $this->user->getTypeOfField('name')
        );
        $query->innerJoin(
            'g',
            $this->table($this->membership),
            'm',
            $this->field('g', $this->group, 'id') . ' = ' . $this->reference('m', $this->membership, 'groups')
            . ' AND ' . $this->reference('m', $this->membership, 'users') . ' = (' . $user->getSQL() . ')',
        );
        foreach ($user->getParameters() as $name => $value) {
            $query->setParameter($name, $value, $user->getParameterType($name));
        }
        return $this->groupRows($query);
    }

    private function taskGroups(EntityRestriction $scope): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()
            ->select($this->field('g', $this->group, 'id') . ' AS id')
            ->from($this->table($this->group), 'g');
        $this->selection(
            $query,
            $this->field('g', $this->group, 'is_task'),
            true,
            'task',
            $this->group->getTypeOfField('is_task')
        );
        if ($scope->entities !== null) {
            $column = $this->reference('g', $this->group, 'entities');
            $direct = $this->entitySelection($query, $column, $scope->entities, $scope->entityList, 'entity');
            if ($scope->ancestors) {
                $recursive = $this->field('g', $this->group, 'is_recursive') . ' = '
                    . $this->parameter($query, 'recursive', true, $this->group->getTypeOfField('is_recursive'));
                $direct = '(' . $direct . ' OR (' . $recursive . ' AND '
                    . $this->entitySelection($query, $column, $scope->ancestors, true, 'ancestor') . '))';
            }
            $query->andWhere($direct);
        }
        return $query;
    }

    private function entitySelection(QueryBuilder $query, string $column, array $entities, bool $list, string $prefix): string
    {
        if (!$entities) {
            return '1 = 0';
        }
        $values = [];
        foreach ($entities as $position => $entity) {
            $values[] = $this->parameter($query, $prefix . $position, $entity, $this->group->getTypeOfField('id'));
        }
        return $column . ($list ? ' IN (' . implode(', ', $values) . ')' : ' = ' . $values[0]);
    }

    private function selection(QueryBuilder $query, string $column, mixed $value, string $name, string $type): void
    {
        $query->andWhere($value === null ? $column . ' IS NULL'
            : $column . ' = ' . $this->parameter($query, $name, $value, $type));
    }

    private function parameter(QueryBuilder $query, string $name, mixed $value, string $type): string
    {
        $query->setParameter($name, $value, $type);
        return Type::getType($type)->convertToDatabaseValueSQL(':' . $name, $this->connection->getDatabasePlatform());
    }

    private function table(ClassMetadata $metadata): string
    {
        return $this->quote->getTableName($metadata, $this->connection->getDatabasePlatform());
    }

    private function field(string $alias, ClassMetadata $metadata, string $property): string
    {
        return $alias . '.' . $this->quote->getColumnName($property, $metadata, $this->connection->getDatabasePlatform());
    }

    private function reference(string $alias, ClassMetadata $metadata, string $property): string
    {
        return $alias . '.' . $this->quote->getJoinColumnName(
            $metadata->associationMappings[$property]->joinColumns[0],
            $metadata,
            $this->connection->getDatabasePlatform(),
        );
    }

    private function groupRows(QueryBuilder $query): array
    {
        $rows = $query->executeQuery()->fetchAllAssociative();
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            foreach ($rows as &$row) {
                $row['id'] = RecordRepository::legacyScalarValue($row['id'], $this->group->getTypeOfField('id'));
            }
        }
        return $rows;
    }
}
