<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use itsmng\Database\Mapping\ReferenceKind;

/** Current required schema for read-only inspection; installation replays frozen history. */
final class BaselineSchema
{
    private array $extraSql = [];

    public function build(AbstractPlatform $platform, bool $foreignKeys = true): Schema
    {
        $this->extraSql = [];
        $baseline = new Migration\V220\Baseline();
        $schema = $baseline->build($platform);
        $this->extraSql['baseline'] = $baseline->extraSql($platform);
        // Adoption retains this redundant historical index on old installations.
        // It is optional beside the current numeric dashboard primary key.
        $schema->getTable('glpi_dashboards')->dropIndex('dashboard_legacy_id');
        foreach (['glpi_slms', 'glpi_slas', 'glpi_olas'] as $tableName) {
            Migration\V220\ServiceLevelCalendars::configureTable($schema->getTable($tableName));
        }
        foreach ([...EntityRegistry::relationsByPolicy(Mapping\ReferenceKind::Audience), ...EntityRegistry::relationsByPolicy(Mapping\ReferenceKind::GlobalScope)] as $name => $relations) {
            $schema->getTable($name)->getColumn('entities_id')->setNotnull(false)->setDefault(null);
        }
        Migration\V220\DashboardOwnership::configureTable($schema->getTable('glpi_dashboards'), $platform);
        Migration\V220\OidcReferences::configureTable($schema->getTable('glpi_oidc_users'));
        $this->configureInheritedReferences($schema, $platform);
        Migration\V220\EntityParents::configureTable($schema->getTable('glpi_entities'));
        Migration\V220\NotificationRecipients::configureTable($schema->getTable('glpi_notificationtargets'));
        Migration\V220\UserAuthenticationSources::configureTable($schema->getTable('glpi_users'));
        Migration\V220\NetworkPortAggregateOrigins::configureSchema($schema);
        Migration\V220\PlanningEventGuests::configureSchema($schema);
        Migration\V220\UnusedProjectTemplateReference::configureTable($schema->getTable('glpi_projects'));
        $this->configurePropertyColumns($schema, $platform);
        $this->configureRequiredSubjects($schema, $platform);
        $this->extraSql['glpi_users'][] = Migration\V220\UserAuthenticationSources::checkSql();
        $this->extraSql['glpi_notificationtargets'][] = Migration\V220\NotificationRecipients::checkSql();
        $this->extraSql['glpi_entities'][] = Migration\V220\EntityParents::checkSql();
        $this->extraSql['glpi_slms'][] = Migration\V220\ServiceLevelCalendars::checkSql();
        foreach (EntityRegistry::relationsByPolicy(Mapping\ReferenceKind::EmptySelection) as $tableName => $relations) {
            foreach ($relations as $column => $target) {
                $schema->getTable($tableName)->getColumn($column)->setNotnull(false)->setDefault(null);
            }
        }
        Migration\V220\DisplayPreferenceOwnership::addToTable($schema->getTable('glpi_displaypreferences'), $platform);
        Migration\V220\KanbanOwnership::addToTable($schema->getTable('glpi_items_kanbans'), $platform);
        Migration\V220\InventoryUniqueness::addToTable($schema->getTable('glpi_items_operatingsystems'), Migration\V220\InventoryUniqueness::indexName($platform));
        Migration\V220\IdentifierColumns::configureSchema($schema);
        if ($foreignKeys) {
            (new ForeignKeys())->addToSchema($schema);
        }
        return $schema;
    }

    public function toSql(AbstractPlatform $platform, bool $foreignKeys = true): array
    {
        $schema = $this->build($platform, $foreignKeys);
        return array_merge($schema->toSql($platform), ...array_values($this->extraSql));
    }

    /** Current schema inspection uses entity policies; historical replay remains immutable. */
    private function configurePropertyColumns(Schema $schema, AbstractPlatform $platform): void
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
        $em = new \Doctrine\ORM\EntityManager($connection, Orm::configuration($platform));
        try {
            $metadata = $em->getMetadataFactory()->getAllMetadata();
            $nativeTimestamps = NativeTimestampSchema::declarations($metadata);
            $mapped = (new \Doctrine\ORM\Tools\SchemaTool($em))->getSchemaFromMetadata($metadata);
            $declarations = [];
            foreach ($metadata as $entity) {
                $declarations[$entity->getTableName()] = $entity;
            }
            foreach ($mapped->getTables() as $declaration) {
                $table = $schema->getTable($declaration->getName());
                $entity = $declarations[$declaration->getName()];
                $ownedIndexes = $ownedIndexColumns = [];
                foreach ((new \ReflectionClass($entity->name))->getAttributes(Mapping\SchemaIndex::class) as $attribute) {
                    $index = $attribute->newInstance();
                    $ownedIndexes[] = $index->name($platform);
                    array_push($ownedIndexColumns, ...$index->columns);
                }
                $subjectColumns = [];
                NativeTimestampSchema::replaceOwnedColumns($table, $declaration, $nativeTimestamps[$entity->getTableName()] ?? []);
                foreach ($nativeTimestamps[$entity->getTableName()] ?? [] as $column => $timestamp) {
                    foreach ($timestamp->touchStatementPrefixes($platform) as $prefix) {
                        $this->extraSql['baseline'] = array_values(array_filter($this->extraSql['baseline'], static fn ($sql) => !str_starts_with($sql, $prefix)));
                    }
                    $this->extraSql[$entity->getTableName()] = [...($this->extraSql[$entity->getTableName()] ?? []), ...$timestamp->touchSql($platform, $entity->getTableName(), $column)];
                }
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
                    if ((new \ReflectionProperty($entity->name, $property))->getAttributes(Mapping\DiscriminatedBy::class)) {
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
        } finally {
            $connection->close();
        }
    }

    /** Current schema inspection uses entity policies; historical replay remains immutable. */
    private function configureInheritedReferences(Schema $schema, AbstractPlatform $platform): void
    {
        foreach (array_keys(EntityRegistry::tables()) as $name) {
            foreach (EntityRegistry::references($name) as $reference) {
                if ($reference->policy->kind !== ReferenceKind::Inherited) {
                    continue;
                }
                $table = $schema->getTable($name);
                $table->getColumn($reference->column)->setNotnull(false)->setDefault(null);
                $table->addColumn('`' . $reference->modeColumn . '`', Types::STRING, [
                    'length' => $reference->modeLength,
                    'notnull' => true,
                    'default' => $reference->defaultMode->value,
                ]);
                $column = $platform->quoteIdentifier($reference->column);
                $mode = $platform->quoteIdentifier($reference->modeColumn);
                $constraint = $platform->quoteIdentifier($name . '_' . $reference->modeColumn . '_selection');
                $choices = $reference->policy->emptyZero ? "'explicit', 'inherit'" : "'explicit', 'inherit', 'unchanged'";
                $selected = $reference->policy->emptyZero ? "($column IS NULL OR $column > 0)" : "($column IS NOT NULL AND $column >= 0)";
                $this->extraSql[$name][] = 'ALTER TABLE ' . $table->getQuotedName($platform) . ' ADD CONSTRAINT ' . $constraint
                    . " CHECK ($mode IN ($choices) AND (($mode = 'explicit' AND $selected) OR ($mode <> 'explicit' AND $column IS NULL)))";
            }
        }
    }

    private function configureRequiredSubjects(Schema $schema, AbstractPlatform $platform): void
    {
        // An explicit version keeps offline schema inspection independent of a server.
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
        $em = new \Doctrine\ORM\EntityManager($connection, Orm::configuration($platform));
        try {
            foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
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
                    if ($platform instanceof AbstractMySQLPlatform && $field->type === Types::BOOLEAN) {
                        $name = BooleanDomainSchema::name($metadata->getTableName(), $field->columnName);
                        $this->extraSql[$metadata->getTableName()][] = 'ALTER TABLE ' . $platform->quoteIdentifier($metadata->getTableName())
                            . ' ADD CONSTRAINT ' . $platform->quoteIdentifier($name) . ' CHECK (' . BooleanDomainSchema::expression($platform, $field->columnName, (bool)$field->nullable) . ')'
                            . ($platform instanceof MySQLPlatform ? ' ENFORCED' : '');
                    }
                    foreach ((new \ReflectionProperty($metadata->name, $property))->getAttributes(Mapping\DiscriminatorKey::class) as $attribute) {
                        $key = $attribute->newInstance();
                        if ($key->fallbackProperty !== null) {
                            continue;
                        }
                        $key->configureSubjectTable($schema->getTable($metadata->getTableName()), $platform, $metadata, $property);
                        $this->extraSql[$metadata->getTableName()][] = $key->subjectCheckSql($platform, $metadata, $property);
                    }
                }
            }
        } finally {
            $connection->close();
        }
    }

}
