<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Mapping\MappedReference;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use LogicException;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionProperty;

/** Read-only lookup derived from entity attributes; contains no schema declarations. */
final class EntityRegistry
{
    private static ?array $model = null;

    public static function tables(): array
    {
        return self::model()['tables'];
    }

    /** Exact public item classes declared by itemtype owning-reference branches. */
    public static function legacyTables(): array
    {
        return self::model()['legacy_tables'];
    }

    /** Compatibility role derived only from the annotated owning association. */
    public static function entityScopeOwner(string $table): ?array
    {
        return self::model()['scope_owners'][$table] ?? null;
    }

    /** Default core scalar IDs, derived once without retaining mutable ORM metadata. */
    public static function scalarIdentifiers(): array
    {
        return self::model()['scalar_identifiers'];
    }

    /** Small physical projection for the private component count, derived from mapped properties. */
    public static function componentCountMapping(string $table): ?array
    {
        return self::model()['component_counts'][$table] ?? null;
    }

    /** Sparse physical fields for the fixed reservation/user display projection. */
    public static function reservationUserMapping(): ?array
    {
        return self::model()['reservation_user'] ?? null;
    }

    /** Fixed historical link fields, derived from the owning followup metadata. */
    public static function promotionSourceMapping(): ?array
    {
        return self::model()['promotion_source'] ?? null;
    }

    /** Physical facts for fixed computer connection reads, without mutable metadata. */
    public static function computerItemMapping(): ?array
    {
        return self::model()['computer_item'] ?? null;
    }

    /** Cache fields and owning self-parent names for private tree point reads. */
    public static function treePointMapping(string $table): ?array
    {
        return self::model()['tree_points'][$table] ?? null;
    }

    public static function booleanColumns(): array
    {
        return self::model()['booleans'];
    }

    /** Physical instant/touch policy is declared once, on its owning temporal property. */
    public static function nativeTimestamps(): array
    {
        return self::model()['native_timestamps'];
    }

    /** Nullability belongs to the same mapped properties as the flag's type. */
    public static function booleanFields(string $table): array
    {
        return self::model()['boolean_fields'][$table] ?? [];
    }

    public static function isBoolean(string $table, string $column): bool
    {
        return (self::model()['types'][$table][$column] ?? null) === 'boolean';
    }

    /** Canonical scalar projection types derived from the owning attributes. */
    public static function fieldTypes(string $table): array
    {
        return self::model()['types'][$table] ?? [];
    }

    /** Enum hydration belongs to the same scalar property declaration as its type. */
    public static function fieldEnums(string $table): array
    {
        return self::model()['enums'][$table] ?? [];
    }

    /** Scalar fields and owning join columns, including generated compatibility identities. */
    public static function columnNames(string $table): array
    {
        return array_values(array_unique([
            ...array_keys(self::model()['types'][$table] ?? []),
            ...array_keys(self::model()['relations'][$table] ?? []),
        ]));
    }

    /** Generated compatibility fields are never copied as physical clone writes. */
    public static function readOnlyColumns(string $table): array
    {
        return self::model()['read_only'][$table] ?? [];
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
        $cache = $GLOBALS['GLPI_CACHE'] ?? null;
        if ($cache instanceof CacheInterface) {
            return self::$model = (new EntityRegistryCache($cache, MappingFingerprint::current()))->load(self::buildModel(...));
        }
        return self::$model = self::buildModel();
    }

    private static function reservationUserProjection(EntityManager $manager): ?array
    {
        $reservation = $manager->getClassMetadata(Entity\Reservation::class);
        $owner = $reservation->associationMappings['reservationitems'] ?? null;
        if ($owner === null || !$owner->isToOneOwningSide() || count($owner->joinColumns) !== 1) {
            return null;
        }
        $item = $manager->getClassMetadata($owner->targetEntity);
        $projection = [];
        foreach (['r' => [$reservation, ['id', 'begin', 'end', 'comment'], ['users', 'reservationitems']],
            'i' => [$item, ['id', 'itemtype', 'items_id'], ['entities']]] as $alias => [$metadata, $fields, $references]) {
            if (!$metadata->isInheritanceTypeNone() || $metadata->identifier !== ['id'] || !empty($metadata->table['schema'])) {
                return null;
            }
            $part = ['table' => [$metadata->table['name'], isset($metadata->table['quoted'])], 'fields' => [], 'references' => []];
            foreach ($fields as $property) {
                if (!$metadata->hasField($property)) {
                    return null;
                }
                $field = $metadata->fieldMappings[$property];
                $part['fields'][$property] = [$field->columnName, isset($field->quoted), $field->type];
            }
            foreach ($references as $property) {
                $reference = $metadata->associationMappings[$property] ?? null;
                if ($reference === null || !$reference->isToOneOwningSide() || count($reference->joinColumns) !== 1) {
                    return null;
                }
                $join = $reference->joinColumns[0];
                $part['references'][$property] = [$join->name, isset($join->quoted), $join->referencedColumnName];
            }
            $projection[$alias] = $part;
        }
        return $projection;
    }

    private static function promotionSourceProjection(EntityManager $manager): ?array
    {
        $metadata = $manager->getClassMetadata(Entity\ITILFollowup::class);
        if ($metadata->identifier !== ['id'] || !$metadata->isInheritanceTypeNone() || !empty($metadata->table['schema'])) {
            return null;
        }
        $projection = ['table' => [$metadata->table['name'], isset($metadata->table['quoted'])], 'fields' => [], 'references' => []];
        foreach (['id', 'itemtype'] as $property) {
            if (!$metadata->hasField($property)) {
                return null;
            }
            $field = $metadata->fieldMappings[$property];
            if ($field->enumType !== null) {
                return null;
            }
            $projection['fields'][$property] = [$field->columnName, isset($field->quoted), $field->type];
        }
        foreach (['ticket', 'promotedTicket'] as $property) {
            $reference = $metadata->associationMappings[$property] ?? null;
            if ($reference === null || !$reference->isToOneOwningSide() || count($reference->joinColumns) !== 1) {
                return null;
            }
            $join = $reference->joinColumns[0];
            $projection['references'][$property] = [$join->name, isset($join->quoted)];
        }
        return $projection;
    }

    private static function computerItemProjection(EntityManager $manager): ?array
    {
        $metadata = $manager->getClassMetadata(Entity\ComputerItem::class);
        $association = $metadata->associationMappings['computers'] ?? null;
        if (!$metadata->isInheritanceTypeNone() || $metadata->identifier !== ['id']
            || !empty($metadata->table['schema']) || $association === null
            || !$association->isToOneOwningSide() || count($association->joinColumns) !== 1) {
            return null;
        }
        $projection = ['table' => [$metadata->table['name'], isset($metadata->table['quoted'])], 'fields' => []];
        foreach (['id', 'itemtype', 'items_id'] as $property) {
            if (!$metadata->hasField($property)) {
                return null;
            }
            $field = $metadata->fieldMappings[$property];
            if ($field->enumType !== null) {
                return null;
            }
            $projection['fields'][$property] = [$field->columnName, isset($field->quoted), $field->type];
        }
        $join = $association->joinColumns[0];
        $projection['computer'] = [$join->name, isset($join->quoted)];
        return $projection;
    }

    private static function buildModel(): array
    {
        // Mapping inspection must also work before installation. The explicit
        // server version prevents platform discovery from opening a connection.
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
        $platform = new MySQLPlatform();
        $configuration = Orm::configuration($platform);
        $em = new EntityManager($connection, $configuration);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $nativeTimestamps = NativeTimestampSchema::declarations($metadata);
        $legacyTables = $tables = $types = $enums = $booleans = $booleanFields = $relations = $references = $discriminators = $lifecycle = $readOnly = $scopeOwners = [];
        $scalarIdentifiers = $componentCounts = $treePoints = [];
        foreach ($metadata as $record) {
            if (count($record->identifier) === 1) {
                $identifier = $record->getSingleIdentifierFieldName();
                if ($record->hasField($identifier)) {
                    $scalarIdentifiers[$record->name] = [
                        'property' => $identifier,
                        'column' => $record->getColumnName($identifier),
                        'type' => $record->getTypeOfField($identifier),
                    ];
                }
            }
            $table = $record->getTableName();
            if (isset($tables[$table])) {
                throw new LogicException('Duplicate mapped core table: ' . $table);
            }
            $tables[$table] = $record->name;
            if ($record->identifier === ['id'] && $record->isInheritanceTypeNone()
                && !array_diff(['id', 'items_id', 'itemtype', 'is_deleted'], array_keys($record->fieldMappings))
                && empty($record->table['schema'])) {
                $countMapping = ['table' => [$record->table['name'], isset($record->table['quoted'])],
                    'fields' => [], 'subjects' => []];
                foreach (['id', 'items_id', 'itemtype', 'is_deleted'] as $property) {
                    $field = $record->fieldMappings[$property];
                    $countMapping['fields'][$property] = [$field->columnName, isset($field->quoted)];
                }
                $componentCounts[$table] = $countMapping;
            }
            if ($record->identifier === ['id'] && $record->hasField('id')
                && $record->isInheritanceTypeNone() && empty($record->table['schema'])) {
                $fields = [];
                foreach (['id', 'sons_cache', 'ancestors_cache'] as $property) {
                    if ($record->hasField($property)) {
                        $field = $record->fieldMappings[$property];
                        $fields[$field->columnName] = [$field->columnName, isset($field->quoted), $field->type];
                    }
                }
                foreach ($record->associationMappings as $association) {
                    if ($association->isToOneOwningSide() && count($association->joinColumns) === 1
                        && $association->targetEntity === $record->name) {
                        $join = $association->joinColumns[0];
                        $fields[$join->name] = [$join->name, isset($join->quoted), null];
                    }
                }
                // A plain identifier alone is not a tree projection.
                if (count($fields) > 1) {
                    $treePoints[$table] = ['table' => [$record->table['name'], isset($record->table['quoted'])],
                        'identifier' => $record->getColumnName('id'), 'fields' => $fields];
                }
            }
            foreach ($record->fieldMappings as $mapping) {
                $types[$table][$mapping->columnName] = $mapping->type;
                if ($mapping->enumType !== null) {
                    $enums[$table][$mapping->columnName] = $mapping->enumType;
                }
                if ($mapping->notInsertable && $mapping->notUpdatable) {
                    $readOnly[$table][] = $mapping->columnName;
                }
                if ($mapping->type === 'boolean') {
                    $booleans[$table][] = $mapping->columnName;
                    $booleanFields[$table][$mapping->columnName] = (bool)$mapping->nullable;
                }
            }
            foreach ($record->fieldMappings as $property => $mapping) {
                foreach ((new ReflectionProperty($record->name, $property))->getAttributes(Mapping\DiscriminatorKey::class) as $attribute) {
                    $key = $attribute->newInstance();
                    $discriminators[$table][$mapping->columnName]['empty_value'] = $key->emptyValue;
                    $discriminators[$table][$mapping->columnName]['fallback_column'] = $key->fallbackProperty === null
                        ? null : $record->getColumnName($key->fallbackProperty);
                }
            }
            foreach ($record->associationMappings as $property => $association) {
                if (!$association->isToOneOwningSide()) {
                    continue;
                }
                foreach ($association->joinColumns as $join) {
                    if ($join->referencedColumnName !== 'id') {
                        throw new LogicException('Foreign key requires explicit composite-target support: ' . $table . '.' . $join->name);
                    }
                    $target = $em->getClassMetadata($association->targetEntity)->getTableName();
                    $relations[$table][$join->name] = $target;
                    $propertyMetadata = new ReflectionProperty($record->name, $property);
                    if ($propertyMetadata->getAttributes(Mapping\EntityScopeOwner::class)) {
                        if (isset($scopeOwners[$table]) || count($association->joinColumns) !== 1) {
                            throw new LogicException('An entity scope requires one explicit owning parent: ' . $table);
                        }
                        $scopeOwners[$table] = ['column' => $join->name, 'target' => $target];
                    }
                    $logicalColumn = $join->name;
                    $logicalDiscriminator = null;
                    foreach ($propertyMetadata->getAttributes(Mapping\DiscriminatedBy::class) as $attribute) {
                        $binding = $attribute->newInstance();
                        if (isset($componentCounts[$table]) && $binding->legacyColumn === 'items_id'
                            && $binding->discriminator === 'itemtype' && count($association->joinColumns) === 1) {
                            $componentCounts[$table]['subjects'][$property] = [$join->name, isset($join->quoted)];
                        }
                        $logicalColumn = $binding->legacyColumn;
                        if (!$record->hasField($binding->discriminator) || !$record->hasField($record->getFieldName($binding->legacyColumn))) {
                            throw new LogicException('Discriminated reference requires mapped legacy fields');
                        }
                        $discriminators[$table][$binding->legacyColumn]['discriminator'] = $record->getColumnName($binding->discriminator);
                        if ($binding->discriminator === 'itemtype') {
                            $logicalDiscriminator = $record->getColumnName($binding->discriminator);
                        }
                        foreach ($binding->values as $value) {
                            if (isset($discriminators[$table][$binding->legacyColumn]['selections'][$value])) {
                                throw new LogicException('Duplicate discriminated reference kind');
                            }
                            $discriminators[$table][$binding->legacyColumn]['selections'][$value] = ['column' => $join->name, 'target' => $target, 'empty_value' => $binding->emptyValue];
                            if ($logicalDiscriminator !== null) {
                                if (isset($legacyTables[$value]) && $legacyTables[$value] !== $target) {
                                    throw new LogicException('Conflicting itemtype target table: ' . $value);
                                }
                                $legacyTables[$value] = $target;
                            }
                        }
                    }
                    $child = ($propertyMetadata->getAttributes(Mapping\ApplicationManaged::class) ? '_' : '') . $table;
                    $lifecycle[$target][$child][] = $logicalColumn;
                    if ($logicalDiscriminator !== null) {
                        $lifecycle[$target][$child][] = $logicalDiscriminator;
                    }
                    $attributes = (new ReflectionProperty($record->name, $property))->getAttributes(ReferencePolicy::class);
                    if ($attributes) {
                        $policy = $attributes[0]->newInstance();
                        if (in_array($policy->kind, [ReferenceKind::RootEntity, ReferenceKind::Audience, ReferenceKind::GlobalScope, ReferenceKind::RootParent], true) && $target !== 'glpi_entities') {
                            throw new LogicException('Entity scope policy requires an entity target: ' . $table . '.' . $join->name);
                        }
                        $defaultMode = ReferenceMode::Explicit;
                        $modeColumn = $modeLength = null;
                        if ($policy->kind === ReferenceKind::Inherited) {
                            $mode = $record->getFieldMapping($policy->modeProperty);
                            if ($mode->enumType !== ReferenceMode::class) {
                                throw new LogicException('Inherited reference mode must use ReferenceMode: ' . $table . '.' . $join->name);
                            }
                            $defaultMode = ReferenceMode::from($mode->options['default']);
                            $modeColumn = $mode->columnName;
                            $modeLength = $mode->length;
                        }
                        if ($policy->kind !== ReferenceKind::RootEntity && !$join->nullable) {
                            throw new LogicException('Sentinel reference must be nullable: ' . $table . '.' . $join->name);
                        }
                        $references[$table][$join->name] = new MappedReference($property, $join->name, $target, $policy, $defaultMode, $modeColumn, $modeLength);
                    }
                }
            }
            foreach ((new ReflectionClass($record->name))->getProperties() as $property) {
                foreach ($property->getAttributes(Mapping\PolymorphicReference::class) as $attribute) {
                    $binding = $attribute->newInstance();
                    if (!$record->hasField($property->name) || !$record->hasField($binding->discriminator)) {
                        throw new LogicException('Polymorphic lifecycle link requires mapped ID and discriminator fields');
                    }
                    $target = $em->getClassMetadata($binding->target)->getTableName();
                    $child = ($binding->managed ? '_' : '') . $table;
                    $lifecycle[$target][$child][] = $record->getColumnName($property->name);
                    $lifecycle[$target][$child][] = $record->getColumnName($binding->discriminator);
                }
                foreach ($property->getAttributes(Mapping\VirtualAssetLink::class) as $attribute) {
                    $binding = $attribute->newInstance();
                    if (!$record->hasField($property->name) || !$record->hasField($binding->discriminator)) {
                        throw new LogicException('Virtual asset link requires mapped ID and discriminator fields');
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
        $reservationUser = self::reservationUserProjection($em);
        $promotionSource = self::promotionSourceProjection($em);
        $computerItem = self::computerItemProjection($em);
        $connection->close();
        // Only immutable lookup projections survive bootstrap, not the offline unit of work.
        unset($em, $metadata, $record);
        gc_collect_cycles();
        return ['legacy_tables' => $legacyTables, 'tables' => $tables, 'types' => $types, 'enums' => $enums, 'booleans' => $booleans, 'boolean_fields' => $booleanFields, 'relations' => $relations, 'references' => $references, 'discriminators' => $discriminators, 'lifecycle' => $lifecycle, 'read_only' => $readOnly, 'scope_owners' => $scopeOwners, 'native_timestamps' => $nativeTimestamps, 'scalar_identifiers' => $scalarIdentifiers, 'component_counts' => $componentCounts, 'reservation_user' => $reservationUser, 'tree_points' => $treePoints, 'promotion_source' => $promotionSource, 'computer_item' => $computerItem];
    }
}
