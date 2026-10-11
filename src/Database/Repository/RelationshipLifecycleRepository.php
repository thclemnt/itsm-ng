<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use CommonDBTM;
use CommonTreeDropdown;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use itsmng\Database\Entity\DocumentItem;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\PolymorphicReference;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\PluginRecordSelection;
use itsmng\Database\RecordCriteria;
use ReflectionClass;
use ReflectionProperty;

/** Generic lifecycle selections are derived from owning properties, not column-name guesses. */
final class RelationshipLifecycleRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    private function incoming(string $table): iterable
    {
        $target = EntityRegistry::tables()[$table] ?? throw new InvalidArgumentException('Unmapped lifecycle target');
        foreach (EntityRegistry::lifecycleRelations()[$table] ?? [] as $child => $columns) {
            if (str_starts_with($child, '_')) {
                continue;
            }
            $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$child]);
            foreach ($metadata->associationMappings as $property => $mapping) {
                $reflection = new ReflectionProperty($metadata->name, $property);
                if (!$mapping->isToOneOwningSide() || $mapping->targetEntity !== $target || $reflection->getAttributes(ApplicationManaged::class)) {
                    continue;
                }
                $binding = $reflection->getAttributes(DiscriminatedBy::class);
                yield [$metadata, $property, $binding ? $binding[0]->newInstance()->legacyColumn : $mapping->joinColumns[0]->name, null];
            }
            foreach ((new ReflectionClass($metadata->name))->getProperties() as $property) {
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
        $query = $this->em->createQueryBuilder()
            ->from($metadata->name, 'r')
            ->where('r.' . $property . ' = :reference')
            ->setParameter('reference', $discriminator === null ? $physicalId : $logicalId, Types::INTEGER);
        if ($discriminator !== null) {
            $query
                ->andWhere('r.' . $discriminator . ' = :itemtype')
                ->setParameter('itemtype', $itemtype, Types::STRING);
        }
        return $query;
    }

    /** Snapshot the child's public key before its update hooks mutate references. */
    public function replacements(string $table, int $physicalId, int $logicalId, string $itemtype, callable $indexColumn): iterable
    {
        if (!isset(EntityRegistry::tables()[$table])) {
            if ($this->lifecycleModel($table)->getType() !== $itemtype) {
                throw new InvalidArgumentException('Plugin replacement requires its actual model type.');
            }
            return;
        }
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
                ->select($expression . ' AS public_id')
                ->orderBy('r.id')->getQuery()->getScalarResult(), 'public_id');
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
        return $query
            ->select('r.id AS id')
            ->andWhere('IDENTITY(' . $alias . '.' . $owner . ') NOT IN (:entities)')
            ->setParameter('entities', array_map('intval', $entities) ?: [-1])
            ->setMaxResults(1)->getQuery()->getScalarResult() !== [];
    }

    private function linkOutside(ClassMetadata $metadata, QueryBuilder $query, ?string $target, array $entities): bool
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
            if ($peerOwner !== null && $this->outside((clone $query)
                ->innerJoin('r.' . $peer, 'peer'), 'peer', $peerOwner, $entities)) {
                return true;
            }
        }
        return false;
    }

    /** Core records retain ORM policies; declared plugin records use their actual model and schema. */
    public function hasDeclaredOutsideEntities(string $table, array $relations, int $id, string $itemtype, array $entities): bool
    {
        $target = $this->lifecycleTarget($table, $itemtype);
        foreach ($relations[$table] ?? [] as $child => $columns) {
            if (str_starts_with($child, '_')) {
                continue;
            }
            $class = EntityRegistry::tables()[$child] ?? null;
            if ($class === null) {
                if ($this->pluginLinkOutside($table, $child, (array)$columns, $relations, $id, $itemtype, $entities)) {
                    return true;
                }
                continue;
            }
            $metadata = $this->em->getClassMetadata($class);
            $columns = (array)$columns;
            $predicates = in_array('itemtype', $columns, true) ? [['items_id' => $id, 'itemtype' => $itemtype]]
                : array_map(static fn (string $column): array => [$column => $id], $columns);
            foreach ($predicates as $criteria) {
                $query = $this->em->createQueryBuilder()
                    ->from($class, 'r');
                $query
                    ->where((new RecordCriteria($query, $metadata))
                        ->where($criteria));
                if ($this->linkOutside($metadata, $query, $target, $entities)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function lifecycleModel(string $table): CommonDBTM
    {
        if (!preg_match('/^glpi_[a-z0-9_]+$/D', $table)) {
            throw new InvalidArgumentException('Invalid declared lifecycle table');
        }
        $item = getItemForItemtype(getItemTypeForTable($table));
        if (!$item instanceof CommonDBTM || $item->getTable() !== $table) {
            throw new InvalidArgumentException('Declared lifecycle table requires its actual model: ' . $table);
        }
        if (!isset(EntityRegistry::tables()[$table])) {
            return PluginRecordSelection::model($table);
        }
        return $item;
    }

    /** Registered plugin relations retain their selected child's public index. */
    public function declaredIdentifiers(string $table, string $index, array $criteria): array
    {
        if (isset(EntityRegistry::tables()[$table])) {
            return (new RecordRepository($this->em))->identifiers($table, $index, $criteria);
        }
        $selection = new PluginRecordSelection($this->em->getConnection(), $table);
        if ($selection->model->getIndexName() !== $index) {
            throw new InvalidArgumentException('Plugin replacement requires the declared model public index.');
        }
        return $selection->referenceIdentifiers($index, $criteria);
    }

    public function declaredReferenceExists(string $table, array $criteria): bool
    {
        if (isset(EntityRegistry::tables()[$table])) {
            return (new RecordRepository($this->em))->countMatching($table, $criteria) > 0;
        }
        $selection = new PluginRecordSelection($this->em->getConnection(), $table);
        return $selection->referenceIdentifiers($selection->model->getIndexName(), $criteria, 1) !== [];
    }

    private function lifecycleTarget(string $table, string $itemtype): ?string
    {
        $mapped = EntityRegistry::tables()[$table] ?? null;
        if ($mapped === null) {
            $model = $this->lifecycleModel($table);
            if ($model->getType() !== $itemtype || !$model->isEntityAssign()) {
                throw new InvalidArgumentException('Plugin recursion requires its actual entity-assigned model');
            }
        }
        return $mapped;
    }

    /** Inspect only declared plugin links; physical identifiers must belong to real model columns. */
    private function pluginLinkOutside(string $target, string $child, array $columns, array $relations, int $id, string $itemtype, array $entities): bool
    {
        $connection = $this->em->getConnection();
        $schemas = [];
        $models = [];
        $model = function (string $table) use (&$models): CommonDBTM {
            return $models[$table] ??= $this->lifecycleModel($table);
        };
        $column = static function (string $table, string $name, bool $text = false) use ($connection, &$schemas): string {
            $schemas[$table] ??= $connection->createSchemaManager()->introspectTable($table);
            if (!preg_match('/^[a-z][a-z0-9_]*$/D', $name) || !$schemas[$table]->hasColumn($name)
                || !in_array(
                    Type::lookupName($schemas[$table]->getColumn($name)->getType()),
                    $text ? [Types::STRING, Types::ASCII_STRING, Types::TEXT] : [Types::SMALLINT, Types::INTEGER, Types::BIGINT],
                    true
                )) {
                throw new InvalidArgumentException('Invalid declared lifecycle column: ' . $table . '.' . $name);
            }
            return $connection->quoteIdentifier($name);
        };
        $item = $model($child);
        $predicates = in_array('itemtype', $columns, true) ? [['items_id' => $id, 'itemtype' => $itemtype]]
            : array_map(static fn (string $name): array => [$name => $id], $columns);
        $relations = array_merge_recursive(EntityRegistry::lifecycleRelations(), $relations);
        foreach ($predicates as $criteria) {
            $query = $connection->createQueryBuilder()
                ->select('1')
                ->from($connection->quoteIdentifier($child), 'r')
                ->setMaxResults(1);
            foreach ($criteria as $name => $value) {
                $text = is_string($value);
                $query
                    ->andWhere('r.' . $column($child, $name, $text) . ' = :' . $name)
                    ->setParameter($name, $value, $text ? Types::STRING : Types::INTEGER);
            }
            $outside = static function ($selection, string $alias, string $table) use ($column, $entities): bool {
                return $selection
                    ->andWhere($alias . '.' . $column($table, 'entities_id') . ' NOT IN (:allowed_entities)')
                    ->setParameter('allowed_entities', array_map('intval', $entities) ?: [-1], ArrayParameterType::INTEGER)
                    ->executeQuery()->fetchOne() !== false;
            };
            if ($item->isEntityAssign()) {
                if ($outside(clone $query, 'r', $child)) {
                    return true;
                }
                continue;
            }
            foreach ($relations as $peerTable => $children) {
                if ($peerTable === $target || !isset($children[$child])) {
                    continue;
                }
                if ($peerTable === '_virtual_device') {
                    $binding = array_values((array)$children[$child]);
                    if (count($binding) !== 2) {
                        throw new InvalidArgumentException('Invalid declared virtual lifecycle binding');
                    }
                    [$reference, $discriminator] = $binding;
                    $field = $column($child, $discriminator, true);
                    $types = (clone $query)
                        ->select('DISTINCT r.' . $field)
                        ->setMaxResults(null)->executeQuery()->fetchFirstColumn();
                    foreach ($types as $type) {
                        $peer = getItemForItemtype($type);
                        if (!$peer instanceof CommonDBTM) {
                            throw new InvalidArgumentException('Unknown declared virtual lifecycle model');
                        }
                        $peer = $model($peer->getTable());
                        if ($peer->isEntityAssign() && $outside(
                            (clone $query)
                                ->innerJoin(
                                    'r',
                                    $connection->quoteIdentifier($peer->getTable()),
                                    'peer',
                                    'peer.' . $column($peer->getTable(), $peer->getIndexName()) . ' = r.' . $column($child, $reference)
                                )
                                ->andWhere('r.' . $field . ' = :peer_type')
                                ->setParameter('peer_type', $type, Types::STRING),
                            'peer',
                            $peer->getTable()
                        )) {
                            return true;
                        }
                    }
                } elseif (!str_starts_with($peerTable, '_') && $model($peerTable)->isEntityAssign()) {
                    foreach ((array)$children[$child] as $reference) {
                        if ($outside(
                            (clone $query)
                                ->innerJoin(
                                    'r',
                                    $connection->quoteIdentifier($peerTable),
                                    'peer',
                                    'peer.' . $column($peerTable, $model($peerTable)->getIndexName()) . ' = r.' . $column($child, $reference)
                                ),
                            'peer',
                            $peerTable
                        )) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    /** Check core owners, plugin tree children and attached document ownership. */
    public function hasOutsideEntities(string $table, int $physicalId, int $logicalId, string $itemtype, array $entities): bool
    {
        $target = $this->lifecycleTarget($table, $itemtype);
        $pluginTarget = $target === null ? $this->lifecycleModel($table) : null;
        // A tree model owns its child relation even without a plugin hook declaration.
        if ($pluginTarget instanceof CommonTreeDropdown
            && $this->pluginLinkOutside($table, $table, [$pluginTarget->getForeignKeyField()], [], $physicalId, $itemtype, $entities)) {
            return true;
        }
        // Core association metadata cannot declare an unknown plugin target. Its
        // incoming links are checked through the active plugin declarations below.
        foreach ($target === null ? [] : $this->incoming($table) as [$metadata, $property, $column, $discriminator]) {
            $query = $this->selection($metadata, $property, $discriminator, $physicalId, $logicalId, $itemtype);
            if ($this->linkOutside($metadata, $query, $target, $entities)) {
                return true;
            }
        }
        // Documents attached to this item have their own owner, independent of the cached link entity.
        $query = $this->em->createQueryBuilder()
            ->from(DocumentItem::class, 'r')
            ->innerJoin('r.documents', 'document')
            ->where('r.items_id = :id AND r.itemtype = :type')
            ->setParameter('id', $logicalId, Types::INTEGER)
            ->setParameter('type', $itemtype, Types::STRING);
        return $this->outside($query, 'document', 'entities', $entities);
    }
}
