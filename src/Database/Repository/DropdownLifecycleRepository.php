<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use CommonDropdown;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use InvalidArgumentException;
use itsmng\Database\Entity\Location;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\PolymorphicReference;
use itsmng\Database\PluginRecordSelection;
use itsmng\Database\RecordCriteria;
use ReflectionClass;
use ReflectionProperty;

/** Dropdown lifecycle reads follow the owning associations and their local policies. */
final class DropdownLifecycleRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function locationEntity(int $id): ?int
    {
        $row = $this->em->createQueryBuilder()
            ->select('IDENTITY(l.entities) AS entity')
            ->from(Location::class, 'l')
            ->where('l.id = :id')
            ->setParameter('id', $id, Types::INTEGER)->getQuery()->getOneOrNullResult(Query::HYDRATE_SCALAR);
        return $row === null ? null : (int)$row['entity'];
    }

    public function findId(string $table, string $name, array $scope): int
    {
        $class = EntityRegistry::tables()[$table] ?? null;
        if ($class === null) {
            $selection = new PluginRecordSelection($this->em->getConnection(), $table);
            if (!$selection->model instanceof CommonDropdown) {
                throw new InvalidArgumentException('Dropdown import requires an actual dropdown model.');
            }
            $ids = $selection->identifiers('id', ['name' => $name] + $scope, 1);
            return $ids === [] ? -1 : (int)$ids[0];
        }
        $metadata = $this->em->getClassMetadata($class);
        $query = $this->em->createQueryBuilder()
            ->select('r.id AS id')
            ->from($class, 'r');
        $query
            ->where((new RecordCriteria($query, $metadata, false))
                ->where($scope))
            ->andWhere('r.name = :name')
            ->setParameter('name', $name, Types::STRING)
            ->orderBy('r.id')
            ->setMaxResults(1);
        $row = $query->getQuery()->getOneOrNullResult(Query::HYDRATE_SCALAR);
        return $row === null ? -1 : (int)$row['id'];
    }

    public function isUsed(string $table, int $id, string $itemtype): bool
    {
        $target = EntityRegistry::tables()[$table] ?? null;
        if ($target === null) {
            $model = PluginRecordSelection::model($table);
            if (!$model instanceof CommonDropdown || $model->getType() !== $itemtype) {
                throw new InvalidArgumentException('Dropdown usage requires its actual plugin dropdown model.');
            }
            // Unmapped plugins declare their incoming relations through the plugin hook.
            return false;
        }
        foreach (EntityRegistry::lifecycleRelations()[$table] ?? [] as $child => $columns) {
            if (str_starts_with($child, '_')) {
                continue;
            }
            $class = EntityRegistry::tables()[$child];
            $metadata = $this->em->getClassMetadata($class);
            foreach ($metadata->associationMappings as $property => $mapping) {
                if (!$mapping->isToOneOwningSide() || $mapping->targetEntity !== $target
                    || (new ReflectionProperty($class, $property))->getAttributes(ApplicationManaged::class)) {
                    continue;
                }
                $row = $this->em->createQueryBuilder()
                    ->select('r.id AS id')
                    ->from($class, 'r')
                    ->where('r.' . $property . ' = :id')
                    ->setParameter('id', $id, Types::INTEGER)
                    ->setMaxResults(1)
                    ->getQuery()->getOneOrNullResult(Query::HYDRATE_SCALAR);
                if ($row !== null) {
                    return true;
                }
            }
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                foreach ($property->getAttributes(PolymorphicReference::class) as $attribute) {
                    $binding = $attribute->newInstance();
                    if ($binding->managed || $binding->target !== $target) {
                        continue;
                    }
                    $row = $this->em->createQueryBuilder()
                        ->select('r.id AS id')
                        ->from($class, 'r')
                        ->where('r.' . $property->name . ' = :id AND r.' . $binding->discriminator . ' = :type')
                        ->setParameter('id', $id, Types::INTEGER)
                        ->setParameter('type', $itemtype, Types::STRING)
                        ->setMaxResults(1)
                        ->getQuery()->getOneOrNullResult(Query::HYDRATE_SCALAR);
                    if ($row !== null) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
