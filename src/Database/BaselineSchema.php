<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Migration\V220\Baseline as FrozenBaseline;
use itsmng\Database\Migration\V220\DashboardOwnership;
use itsmng\Database\Migration\V220\DisplayPreferenceOwnership;
use itsmng\Database\Migration\V220\EntityParents;
use itsmng\Database\Migration\V220\IdentifierColumns;
use itsmng\Database\Migration\V220\InventoryUniqueness;
use itsmng\Database\Migration\V220\KanbanOwnership;
use itsmng\Database\Migration\V220\NetworkPortAggregateOrigins;
use itsmng\Database\Migration\V220\NotificationRecipients;
use itsmng\Database\Migration\V220\OidcReferences;
use itsmng\Database\Migration\V220\PlanningEventGuests;
use itsmng\Database\Migration\V220\ServiceLevelCalendars;
use itsmng\Database\Migration\V220\UnusedProjectTemplateReference;
use itsmng\Database\Migration\V220\UserAuthenticationSources;
use ReflectionClass;
use ReflectionProperty;

/** Current required schema for read-only inspection; installation replays frozen history. */
final class BaselineSchema
{
    private array $subjectPolicies = [];

    /** Native policies from this same current-schema build, never a historical receipt. */
    public function subjectPolicies(): array
    {
        return $this->subjectPolicies;
    }

    /** Optional mapping source for independently declared current schemas. */
    public function __construct(private readonly ?EntityManager $metadataManager = null)
    {
    }

    public function build(AbstractPlatform $platform, bool $foreignKeys = true): Schema
    {
        if ($this->metadataManager !== null
            && $this->metadataManager->getConnection()->getDatabasePlatform()::class !== $platform::class) {
            throw new InvalidArgumentException('Current schema metadata must use the selected platform.');
        }
        $this->subjectPolicies = [];
        // Frozen Baseline creates Schema() with the default configuration. Own
        // that same configuration explicitly when composing current declarations.
        $configuration = new SchemaConfig();
        $schema = (new FrozenBaseline())->build($platform);
        // Adoption retains this redundant historical index on old installations.
        // It is optional beside the current numeric dashboard primary key.
        $schema->getTable('glpi_dashboards')->dropIndex('dashboard_legacy_id');
        foreach (['glpi_slms', 'glpi_slas', 'glpi_olas'] as $tableName) {
            ServiceLevelCalendars::configureTable($schema->getTable($tableName));
        }
        foreach ([
            ...EntityRegistry::relationsByPolicy(ReferenceKind::Audience),
            ...EntityRegistry::relationsByPolicy(ReferenceKind::GlobalScope),
        ] as $name => $relations) {
            $schema->getTable($name)
                ->getColumn('entities_id')
                ->setNotnull(false)
                ->setDefault(null);
        }
        DashboardOwnership::configureTable($schema->getTable('glpi_dashboards'), $platform);
        OidcReferences::configureTable($schema->getTable('glpi_oidc_users'));
        $this->configureInheritedReferences($schema);
        EntityParents::configureTable($schema->getTable('glpi_entities'));
        NotificationRecipients::configureTable($schema->getTable('glpi_notificationtargets'));
        UserAuthenticationSources::configureTable($schema->getTable('glpi_users'));
        NetworkPortAggregateOrigins::configureSchema($schema);
        PlanningEventGuests::configureSchema($schema);
        UnusedProjectTemplateReference::configureTable($schema->getTable('glpi_projects'));
        $ownedTables = $this->configureCurrentMappings($schema, $platform, $configuration, $foreignKeys);
        foreach (EntityRegistry::relationsByPolicy(ReferenceKind::EmptySelection) as $tableName => $relations) {
            foreach ($relations as $column => $target) {
                $schema->getTable($tableName)
                    ->getColumn($column)
                    ->setNotnull(false)
                    ->setDefault(null);
            }
        }
        DisplayPreferenceOwnership::addToTable($schema->getTable('glpi_displaypreferences'), $platform);
        KanbanOwnership::addToTable($schema->getTable('glpi_items_kanbans'), $platform);
        InventoryUniqueness::addToTable(
            $schema->getTable('glpi_items_operatingsystems'),
            InventoryUniqueness::indexName($platform)
        );
        IdentifierColumns::configureSchema($schema);
        if ($foreignKeys) {
            (new ForeignKeys())->addToSchema($schema);
        }
        return CurrentSchema::replaceTables($schema, $ownedTables, $configuration);
    }

    /** Current schema inspection uses entity policies; historical replay remains immutable. */
    private function configureCurrentMappings(Schema &$schema, AbstractPlatform $platform, SchemaConfig $configuration, bool $foreignKeys): array
    {
        // The explicit version keeps this owned metadata connection offline.
        $connection = $this->metadataManager?->getConnection()
            ?? DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
        $em = $this->metadataManager ?? new EntityManager($connection, Orm::configuration($platform));
        try {
            $metadata = $em->getMetadataFactory()->getAllMetadata();
            $nativeTimestamps = NativeTimestampSchema::declarations($metadata);
            $mapped = (new SchemaTool($em))->getSchemaFromMetadata($metadata);
            $declarations = [];
            foreach ($metadata as $entity) {
                $declarations[$entity->getTableName()] = $entity;
                foreach ($entity->fieldMappings as $property => $field) {
                    foreach ((new ReflectionProperty($entity->name, $property))->getAttributes(BooleanStorage::class) as $attribute) {
                        $attribute->newInstance()->configure($mapped->getTable($entity->getTableName())->getColumn($field->columnName), $platform, $field);
                    }
                }
            }
            $ownedTables = [];
            $newTables = [];
            foreach ($mapped->getTables() as $declaration) {
                $entity = $declarations[$declaration->getName()];
                if ((new ReflectionClass($entity->name))->getAttributes(SchemaOwner::class) !== []) {
                    $owned = clone $declaration;
                    if (!$foreignKeys) {
                        foreach ($owned->getForeignKeys() as $foreignKey) {
                            $owned->removeForeignKey($foreignKey->getName());
                        }
                    }
                    $ownedTables[] = $owned;
                    if (!$schema->hasTable($declaration->getName())) {
                        $newTables[] = $owned;
                    }
                }
            }
            // New owned tables also participate in current native-policy projection.
            // Final replacement keeps complete metadata authoritative after the
            // remaining legacy overlays have run.
            $schema = CurrentSchema::replaceTables($schema, $newTables, $configuration);
            foreach ($mapped->getTables() as $declaration) {
                $table = $schema->getTable($declaration->getName());
                $entity = $declarations[$declaration->getName()];
                $ownedIndexes = $ownedIndexColumns = [];
                foreach ((new ReflectionClass($entity->name))->getAttributes(SchemaIndex::class) as $attribute) {
                    $index = $attribute->newInstance();
                    $ownedIndexes[] = $index->name($platform);
                    array_push($ownedIndexColumns, ...$index->columns);
                }
                $subjectColumns = [];
                NativeTimestampSchema::replaceOwnedColumns($table, $declaration, $nativeTimestamps[$entity->getTableName()] ?? []);
                $ownedKeys = [];
                foreach ($entity->fieldMappings as $property => $field) {
                    $name = trim($field->columnName, '`"');
                    // An explicitly index-owned read-only generated property owns
                    // its native declaration. Other compatibility subjects retain
                    // their platform-aware builders below.
                    if (in_array($name, $ownedIndexColumns, true)
                        && $field->notInsertable && $field->notUpdatable
                        && $field->generated === ClassMetadata::GENERATED_ALWAYS
                        && $field->columnDefinition !== null) {
                        $ownedKeys[] = $name;
                        continue;
                    }
                    if ($field->notInsertable && $field->notUpdatable) {
                        // Compatibility projections retain their platform-aware
                        // metadata builders below, including legacy index names.
                        $subjectColumns[] = trim($field->columnName, '`"');
                    }
                }
                foreach ($entity->associationMappings as $property => $association) {
                    if ((new ReflectionProperty($entity->name, $property))->getAttributes(DiscriminatedBy::class)) {
                        foreach ($association->joinColumns as $join) {
                            $subjectColumns[] = $join->name;
                        }
                    }
                }
                $added = [];
                foreach ($declaration->getColumns() as $column) {
                    $owned = in_array($column->getName(), $ownedKeys, true);
                    if ((!$owned && $table->hasColumn($column->getName())) || in_array($column->getName(), $subjectColumns, true)) {
                        continue;
                    }
                    $options = $column->toArray(true);
                    unset($options['name'], $options['typeName']);
                    $options = array_filter($options, static fn ($name) => method_exists($column, 'set' . $name), ARRAY_FILTER_USE_KEY);
                    if ($table->hasColumn($column->getName())) {
                        $table->modifyColumn($column->getName(), ['type' => $column->getType()] + $options);
                    } else {
                        $table->addColumn($column->getName(), Type::lookupName($column->getType()), $options);
                    }
                    $added[] = $column->getName();
                }
                // Entity-owned physical indexes can also replace an old index
                // on existing fields, without consulting migration table lists.
                foreach ($declaration->getIndexes() as $index) {
                    $explicit = isset($entity->table['indexes'][$index->getName()]) || isset($entity->table['uniqueConstraints'][$index->getName()]);
                    $owned = in_array($index->getName(), $ownedIndexes, true);
                    if ($owned || ($explicit && array_intersect($added, $index->getColumns()) && !$table->hasIndex($index->getName()))) {
                        if ($owned && $table->hasIndex($index->getName())) {
                            $table->dropIndex($index->getName());
                        }
                        $index->isUnique()
                            ? $table->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions())
                            : $table->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
                    }
                }
            }
            // Native policies use the same metadata snapshot as columns and indexes.
            $this->configureRequiredSubjects($schema, $platform, $metadata);
            return $ownedTables;
        } finally {
            if ($this->metadataManager === null) {
                $connection->close();
            }
        }
    }

    /** Current schema inspection uses entity policies; historical replay remains immutable. */
    private function configureInheritedReferences(Schema $schema): void
    {
        foreach (array_keys(EntityRegistry::tables()) as $name) {
            foreach (EntityRegistry::references($name) as $reference) {
                if ($reference->policy->kind !== ReferenceKind::Inherited) {
                    continue;
                }
                $table = $schema->getTable($name);
                $table->getColumn($reference->column)
                    ->setNotnull(false)
                    ->setDefault(null);
                $table->addColumn('`' . $reference->modeColumn . '`', Types::STRING, [
                    'length' => $reference->modeLength,
                    'notnull' => true,
                    'default' => $reference->defaultMode->value,
                ]);
            }
        }
    }

    /** @param list<ClassMetadata> $declarations One current-build metadata snapshot. */
    private function configureRequiredSubjects(Schema $schema, AbstractPlatform $platform, array $declarations): void
    {
        foreach ($declarations as $metadata) {
            foreach ($metadata->fieldMappings as $property => $field) {
                // Logical flags live on their entity properties. MySQL keeps
                // historical integer storage; PostgreSQL uses native booleans.
                if ($platform instanceof PostgreSQLPlatform && $field->type === Types::BOOLEAN) {
                    $column = $schema->getTable($metadata->getTableName())->getColumn($field->columnName);
                    $column->setType(Type::getType(Types::BOOLEAN));
                    if ($column->getDefault() !== null) {
                        $column->setDefault((bool)(int)$column->getDefault());
                    }
                }
                foreach ((new ReflectionProperty($metadata->name, $property))->getAttributes(DiscriminatorKey::class) as $attribute) {
                    $key = $attribute->newInstance();
                    if ($key->fallbackProperty !== null) {
                        continue;
                    }
                    $key->configureSubjectTable($schema->getTable($metadata->getTableName()), $platform, $metadata, $property);
                    $discriminators = [];
                    foreach ($metadata->associationMappings as $association => $mapping) {
                        foreach ((new ReflectionProperty($metadata->name, $association))->getAttributes(DiscriminatedBy::class) as $binding) {
                            $binding = $binding->newInstance();
                            if ($binding->legacyColumn === $metadata->getColumnName($property)) {
                                $discriminators[] = $metadata->getColumnName($binding->discriminator);
                            }
                        }
                    }
                    $this->subjectPolicies[$metadata->getTableName()][$metadata->getColumnName($property)] = [
                        'projection' => $key->projectionExpression($platform, $metadata, $property),
                        'constraint' => $key->subjectConstraintName($metadata),
                        'check' => $key->subjectCheckExpression($platform, $metadata, $property),
                        'discriminators' => array_values(array_unique($discriminators)),
                    ];
                }
            }
        }
    }
}
