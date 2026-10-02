<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Mapping\MappedReference;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

/** Read-only lookup derived from entity attributes; contains no schema declarations. */
final class EntityRegistry
{
    private static ?array $model = null;

    public static function tables(): array
    {
        return self::model()['tables'];
    }

    public static function booleanColumns(): array
    {
        return self::model()['booleans'];
    }

    public static function isBoolean(string $table, string $column): bool
    {
        return (self::model()['types'][$table][$column] ?? null) === 'boolean';
    }

    /** Scalar fields and owning join columns, including generated compatibility identities. */
    public static function columnNames(string $table): array
    {
        return array_values(array_unique([
            ...array_keys(self::model()['types'][$table] ?? []),
            ...array_keys(self::model()['relations'][$table] ?? []),
        ]));
    }

    /** Owning association join columns, not inferred names or nullable scalars. */
    public static function relations(): array
    {
        return self::model()['relations'];
    }

    /** Compatibility view of owning associations and property-local lifecycle policies. */
    public static function lifecycleRelations(): array
    {
        return self::model()['lifecycle'];
    }

    /** @return array<string, MappedReference> */
    public static function references(string $table): array
    {
        return self::model()['references'][$table] ?? [];
    }

    /** Legacy discriminator branches derived from annotations on owning associations. */
    public static function discriminatedReferences(string $table): array
    {
        return self::model()['discriminators'][$table] ?? [];
    }

    public static function hasPolicy(string $table, string $column, ReferenceKind $kind): bool
    {
        return (self::references($table)[$column]->policy->kind ?? null) === $kind;
    }

    /** Derived targets for schema operations; contains no separately declared relationships. */
    public static function relationsByPolicy(ReferenceKind $kind): array
    {
        $relations = [];
        foreach (self::model()['references'] as $table => $references) {
            foreach ($references as $column => $reference) {
                if ($reference->policy->kind === $kind) {
                    $relations[$table][$column] = $reference->targetTable;
                }
            }
        }
        return $relations;
    }

    private static function model(): array
    {
        if (self::$model !== null) {
            return self::$model;
        }
        // Mapping inspection must also work before installation. The explicit
        // server version prevents platform discovery from opening a connection.
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
        $em = new EntityManager($connection, Orm::configuration(new MySQLPlatform()));
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tables = $types = $booleans = $relations = $references = $discriminators = $lifecycle = [];
        foreach ($metadata as $record) {
            $table = $record->getTableName();
            if (isset($tables[$table])) {
                throw new \LogicException('Duplicate mapped core table: ' . $table);
            }
            $tables[$table] = $record->name;
            foreach ($record->fieldMappings as $mapping) {
                $types[$table][$mapping->columnName] = $mapping->type;
                if ($mapping->type === 'boolean') {
                    $booleans[$table][] = $mapping->columnName;
                }
            }
            foreach ($record->associationMappings as $property => $association) {
                if (!$association->isToOneOwningSide()) {
                    continue;
                }
                foreach ($association->joinColumns as $join) {
                    if ($join->referencedColumnName !== 'id') {
                        throw new \LogicException('Foreign key requires explicit composite-target support: ' . $table . '.' . $join->name);
                    }
                    $target = $em->getClassMetadata($association->targetEntity)->getTableName();
                    $relations[$table][$join->name] = $target;
                    $propertyMetadata = new \ReflectionProperty($record->name, $property);
                    $logicalColumn = $join->name;
                    $logicalDiscriminator = null;
                    foreach ($propertyMetadata->getAttributes(Mapping\DiscriminatedBy::class) as $attribute) {
                        $binding = $attribute->newInstance();
                        $logicalColumn = $binding->legacyColumn;
                        if (!$record->hasField($binding->discriminator) || !$record->hasField($record->getFieldName($binding->legacyColumn))) {
                            throw new \LogicException('Discriminated reference requires mapped legacy fields');
                        }
                        $discriminators[$table][$binding->legacyColumn]['discriminator'] = $record->getColumnName($binding->discriminator);
                        if ($binding->discriminator === 'itemtype') {
                            $logicalDiscriminator = $record->getColumnName($binding->discriminator);
                        }
                        foreach ($binding->values as $value) {
                            if (isset($discriminators[$table][$binding->legacyColumn]['selections'][$value])) {
                                throw new \LogicException('Duplicate discriminated reference kind');
                            }
                            $discriminators[$table][$binding->legacyColumn]['selections'][$value] = ['column' => $join->name, 'target' => $target, 'empty_value' => $binding->emptyValue];
                        }
                    }
                    $child = ($propertyMetadata->getAttributes(Mapping\ApplicationManaged::class) ? '_' : '') . $table;
                    $lifecycle[$target][$child][] = $logicalColumn;
                    if ($logicalDiscriminator !== null) {
                        $lifecycle[$target][$child][] = $logicalDiscriminator;
                    }
                    $attributes = (new \ReflectionProperty($record->name, $property))->getAttributes(ReferencePolicy::class);
                    if ($attributes) {
                        $policy = $attributes[0]->newInstance();
                        if (in_array($policy->kind, [ReferenceKind::RootEntity, ReferenceKind::Audience, ReferenceKind::GlobalScope, ReferenceKind::RootParent], true) && $target !== 'glpi_entities') {
                            throw new \LogicException('Entity scope policy requires an entity target: ' . $table . '.' . $join->name);
                        }
                        $defaultMode = ReferenceMode::Explicit;
                        $modeColumn = $modeLength = null;
                        if ($policy->kind === ReferenceKind::Inherited) {
                            $mode = $record->getFieldMapping($policy->modeProperty);
                            if ($mode->enumType !== ReferenceMode::class) {
                                throw new \LogicException('Inherited reference mode must use ReferenceMode: ' . $table . '.' . $join->name);
                            }
                            $defaultMode = ReferenceMode::from($mode->options['default']);
                            $modeColumn = $mode->columnName;
                            $modeLength = $mode->length;
                        }
                        if ($policy->kind !== ReferenceKind::RootEntity && !$join->nullable) {
                            throw new \LogicException('Sentinel reference must be nullable: ' . $table . '.' . $join->name);
                        }
                        $references[$table][$join->name] = new MappedReference($property, $join->name, $target, $policy, $defaultMode, $modeColumn, $modeLength);
                    }
                }
            }
            foreach ((new \ReflectionClass($record->name))->getProperties() as $property) {
                foreach ($property->getAttributes(Mapping\PolymorphicReference::class) as $attribute) {
                    $binding = $attribute->newInstance();
                    if (!$record->hasField($property->name) || !$record->hasField($binding->discriminator)) {
                        throw new \LogicException('Polymorphic lifecycle link requires mapped ID and discriminator fields');
                    }
                    $target = $em->getClassMetadata($binding->target)->getTableName();
                    $child = ($binding->managed ? '_' : '') . $table;
                    $lifecycle[$target][$child][] = $record->getColumnName($property->name);
                    $lifecycle[$target][$child][] = $record->getColumnName($binding->discriminator);
                }
                foreach ($property->getAttributes(Mapping\VirtualAssetLink::class) as $attribute) {
                    $binding = $attribute->newInstance();
                    if (!$record->hasField($property->name) || !$record->hasField($binding->discriminator)) {
                        throw new \LogicException('Virtual asset link requires mapped ID and discriminator fields');
                    }
                    $lifecycle['_virtual_device'][$table] = [$record->getColumnName($property->name), $record->getColumnName($binding->discriminator)];
                }
            }
        }
        foreach ([&$tables, &$types, &$booleans, &$relations] as &$mapping) {
            ksort($mapping);
        }
        ksort($lifecycle);
        foreach ($lifecycle as &$children) {
            ksort($children);
            foreach ($children as &$columns) {
                $columns = array_values(array_unique($columns));
                if (count($columns) === 1) {
                    $columns = $columns[0];
                }
            }
            unset($columns);
        }
        unset($children);
        $connection->close();
        // Only immutable lookup projections survive bootstrap, not the offline unit of work.
        unset($em, $metadata, $record);
        gc_collect_cycles();
        return self::$model = ['tables' => $tables, 'types' => $types, 'booleans' => $booleans, 'relations' => $relations, 'references' => $references, 'discriminators' => $discriminators, 'lifecycle' => $lifecycle];
    }
}
