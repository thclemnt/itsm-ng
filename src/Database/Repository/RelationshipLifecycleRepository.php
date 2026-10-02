<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\PolymorphicReference;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\VirtualAssetLink;
use itsmng\Database\RecordCriteria;

/** Generic lifecycle selections are derived from owning properties, not column-name guesses. */
final class RelationshipLifecycleRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    private function incoming(string $table): iterable
    {
        $target = EntityRegistry::tables()[$table] ?? throw new \InvalidArgumentException('Unmapped lifecycle target');
        foreach (EntityRegistry::lifecycleRelations()[$table] ?? [] as $child => $columns) {
            if (str_starts_with($child, '_')) {
                continue;
            }
            $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$child]);
            foreach ($metadata->associationMappings as $property => $mapping) {
                $reflection = new \ReflectionProperty($metadata->name, $property);
                if (!$mapping->isToOneOwningSide() || $mapping->targetEntity !== $target || $reflection->getAttributes(ApplicationManaged::class)) {
                    continue;
                }
                $binding = $reflection->getAttributes(DiscriminatedBy::class);
                yield [$metadata, $property, $binding ? $binding[0]->newInstance()->legacyColumn : $mapping->joinColumns[0]->name, null];
            }
            foreach ((new \ReflectionClass($metadata->name))->getProperties() as $property) {
                foreach ($property->getAttributes(PolymorphicReference::class) as $attribute) {
                    $binding = $attribute->newInstance();
                    if (!$binding->managed && $binding->target === $target) {
                        yield [$metadata, $property->name, $metadata->getColumnName($property->name), $binding->discriminator];
                    }
                }
            }
        }
    }

    private function selection(ClassMetadata $metadata, string $property, ?string $discriminator, int $physicalId, int $logicalId, string $itemtype): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from($metadata->name, 'r')
            ->where('r.' . $property . ' = :reference')->setParameter('reference', $discriminator === null ? $physicalId : $logicalId, Types::INTEGER);
        if ($discriminator !== null) {
            $query->andWhere('r.' . $discriminator . ' = :itemtype')->setParameter('itemtype', $itemtype, Types::STRING);
        }
        return $query;
    }

    /** Snapshot the child's public key before its update hooks mutate references. */
    public function replacements(string $table, int $physicalId, int $logicalId, string $itemtype, callable $indexColumn): iterable
    {
        foreach ($this->incoming($table) as [$metadata, $property, $column, $discriminator]) {
            $index = $indexColumn($metadata->getTableName());
            if ($index === null || $index === $column) {
                continue;
            }
            $indexProperty = $metadata->getFieldName($index);
            foreach ($metadata->associationMappings as $name => $mapping) {
                if ($mapping->isToOneOwningSide() && $mapping->joinColumns[0]->name === $index) {
                    $indexProperty = $name;
                }
            }
            $expression = $metadata->hasAssociation($indexProperty) ? 'IDENTITY(r.' . $indexProperty . ')' : 'r.' . $indexProperty;
            $ids = array_column($this->selection($metadata, $property, $discriminator, $physicalId, $logicalId, $itemtype)
                ->select($expression . ' AS public_id')->orderBy('r.id')->getQuery()->getScalarResult(), 'public_id');
            if ($ids) {
                yield ['table' => $metadata->getTableName(), 'column' => $column, 'index' => $index, 'physical' => $discriminator === null, 'ids' => $ids];
            }
        }
    }

    /** Entity ownership and audience scopes are local association policies. */
    private function owner(ClassMetadata $metadata): ?string
    {
        foreach (EntityRegistry::references($metadata->getTableName()) as $reference) {
            if (in_array($reference->policy->kind, [ReferenceKind::RootEntity, ReferenceKind::RootParent, ReferenceKind::Audience, ReferenceKind::GlobalScope], true)) {
                return $reference->association;
            }
        }
        return null;
    }

    private function outside(QueryBuilder $query, string $alias, string $owner, array $entities): bool
    {
        return $query->select('r.id AS id')->andWhere('IDENTITY(' . $alias . '.' . $owner . ') NOT IN (:entities)')
            ->setParameter('entities', array_map('intval', $entities) ?: [-1])->setMaxResults(1)->getQuery()->getScalarResult() !== [];
    }

    private function linkOutside(ClassMetadata $metadata, QueryBuilder $query, string $target, array $entities, callable $resolveType): bool
    {
        if (($owner = $this->owner($metadata)) !== null) {
            if ($this->outside(clone $query, 'r', $owner, $entities)) {
                return true;
            }
            return false;
        }
        // A link without its own entity is scoped by each other owning end.
        foreach ($metadata->associationMappings as $peer => $mapping) {
            // ApplicationManaged controls replacement/purge ownership. A
            // read-only recursion check must still inspect that real peer.
            if (!$mapping->isToOneOwningSide() || $mapping->targetEntity === $target) {
                continue;
            }
            $peerOwner = $this->owner($this->em->getClassMetadata($mapping->targetEntity));
            if ($peerOwner !== null && $this->outside((clone $query)->innerJoin('r.' . $peer, 'peer'), 'peer', $peerOwner, $entities)) {
                return true;
            }
        }
        foreach ((new \ReflectionClass($metadata->name))->getProperties() as $asset) {
            foreach ($asset->getAttributes(VirtualAssetLink::class) as $attribute) {
                $binding = $attribute->newInstance();
                $types = (clone $query)->select('DISTINCT r.' . $binding->discriminator . ' AS itemtype')->getQuery()->getScalarResult();
                foreach ($types as $row) {
                    $class = $resolveType($row['itemtype']);
                    if ($class === null) {
                        continue;
                    }
                    $peerOwner = $this->owner($this->em->getClassMetadata($class));
                    if ($peerOwner !== null && $this->outside((clone $query)->innerJoin(
                        $class,
                        'peer',
                        'WITH',
                        'peer.id = r.' . $asset->name . ' AND r.' . $binding->discriminator . ' = :peer_type'
                    )
                        ->setParameter('peer_type', $row['itemtype'], Types::STRING), 'peer', $peerOwner, $entities)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** Plugin declarations require mapped child records and real mapped peer ends. */
    public function hasDeclaredOutsideEntities(string $table, array $links, int $id, string $itemtype, array $entities, callable $resolveType): bool
    {
        $target = EntityRegistry::tables()[$table] ?? throw new \InvalidArgumentException('Unmapped lifecycle target');
        foreach ($links as $child => $columns) {
            if (str_starts_with($child, '_')) {
                continue;
            }
            $class = EntityRegistry::tables()[$child] ?? throw new \InvalidArgumentException('Plugin lifecycle requires a registered entity: ' . $child);
            $metadata = $this->em->getClassMetadata($class);
            $columns = (array)$columns;
            $predicates = in_array('itemtype', $columns, true) ? [['items_id' => $id, 'itemtype' => $itemtype]]
                : array_map(static fn (string $column): array => [$column => $id], $columns);
            foreach ($predicates as $criteria) {
                $query = $this->em->createQueryBuilder()->from($class, 'r');
                $query->where((new RecordCriteria($query, $metadata))->where($criteria));
                if ($this->linkOutside($metadata, $query, $target, $entities, $resolveType)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Resolve dynamic item types through their registered model; never interpolate database strings as DQL. */
    public function hasOutsideEntities(string $table, int $physicalId, int $logicalId, string $itemtype, array $entities, callable $resolveType): bool
    {
        $target = EntityRegistry::tables()[$table] ?? throw new \InvalidArgumentException('Unmapped lifecycle target');
        foreach ($this->incoming($table) as [$metadata, $property, $column, $discriminator]) {
            $query = $this->selection($metadata, $property, $discriminator, $physicalId, $logicalId, $itemtype);
            if ($this->linkOutside($metadata, $query, $target, $entities, $resolveType)) {
                return true;
            }
        }
        // Documents attached to this item have their own owner, independent of the cached link entity.
        $query = $this->em->createQueryBuilder()->from(Entity\DocumentItem::class, 'r')->innerJoin('r.documents', 'document')
            ->where('r.items_id = :id AND r.itemtype = :type')->setParameter('id', $logicalId, Types::INTEGER)
            ->setParameter('type', $itemtype, Types::STRING);
        return $this->outside($query, 'document', 'entities', $entities);
    }
}
