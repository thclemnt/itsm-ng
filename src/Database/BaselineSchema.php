<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\ReferenceKind;

/** Current required schema for read-only inspection; installation replays frozen history. */
final class BaselineSchema
{
    private array $extraSql = [];

    public function build(AbstractPlatform $platform, bool $foreignKeys = true): Schema
    {
        $this->extraSql = [];
        $baseline = new Migration\Baseline20261001();
        $schema = $baseline->build($platform);
        $this->extraSql['baseline'] = $baseline->extraSql($platform);
        // Adoption retains this redundant historical index on old installations.
        // It is optional beside the current numeric dashboard primary key.
        $schema->getTable('glpi_dashboards')->dropIndex('dashboard_legacy_id');
        foreach (['glpi_slms', 'glpi_slas', 'glpi_olas'] as $tableName) {
            Migration\ServiceLevelCalendars::configureTable($schema->getTable($tableName));
        }
        foreach ([...EntityRegistry::relationsByPolicy(Mapping\ReferenceKind::Audience), ...EntityRegistry::relationsByPolicy(Mapping\ReferenceKind::GlobalScope)] as $name => $relations) {
            $schema->getTable($name)->getColumn('entities_id')->setNotnull(false)->setDefault(null);
        }
        Migration\DashboardOwnership::configureTable($schema->getTable('glpi_dashboards'), $platform);
        Migration\OidcReferences::configureTable($schema->getTable('glpi_oidc_users'));
        $this->configureInheritedReferences($schema, $platform);
        Migration\EntityParents::configureTable($schema->getTable('glpi_entities'));
        Migration\NotificationRecipients::configureTable($schema->getTable('glpi_notificationtargets'));
        Migration\UserAuthenticationSources::configureTable($schema->getTable('glpi_users'));
        Migration\NetworkPortAggregateOrigins::configureSchema($schema);
        Migration\PlanningEventGuests::configureSchema($schema);
        Migration\UnusedProjectTemplateReference::configureTable($schema->getTable('glpi_projects'));
        Migration\ConsumableRecipients::configureTable($schema->getTable('glpi_consumables'));
        $this->extraSql['glpi_consumables'][] = Migration\ConsumableRecipients::checkSql('glpi_consumables');
        $this->configureRequiredSubjects($schema, $platform);
        $this->extraSql['glpi_users'][] = Migration\UserAuthenticationSources::checkSql();
        $this->extraSql['glpi_notificationtargets'][] = Migration\NotificationRecipients::checkSql();
        $this->extraSql['glpi_entities'][] = Migration\EntityParents::checkSql();
        $this->extraSql['glpi_slms'][] = Migration\ServiceLevelCalendars::checkSql();
        foreach (EntityRegistry::relationsByPolicy(Mapping\ReferenceKind::EmptySelection) as $tableName => $relations) {
            foreach ($relations as $column => $target) {
                $schema->getTable($tableName)->getColumn($column)->setNotnull(false)->setDefault(null);
            }
        }
        foreach (array_keys(Migration\ActorUniqueness::TABLES) as $table) {
            Migration\ActorUniqueness::addToTable($schema->getTable($table), $platform);
        }
        Migration\DisplayPreferenceOwnership::addToTable($schema->getTable('glpi_displaypreferences'), $platform);
        Migration\KanbanOwnership::addToTable($schema->getTable('glpi_items_kanbans'), $platform);
        Migration\InventoryUniqueness::addToTable($schema->getTable('glpi_items_operatingsystems'), Migration\InventoryUniqueness::indexName($platform));
        foreach (array_keys(Migration\TreeUniqueness::TABLES) as $table) {
            Migration\TreeUniqueness::addToTable($schema->getTable($table), $platform);
        }
        Migration\IdentifierColumns::configureSchema($schema);
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
                    foreach ((new \ReflectionProperty($metadata->name, $property))->getAttributes(Mapping\DiscriminatorKey::class) as $attribute) {
                        $key = $attribute->newInstance();
                        if ($key->fallbackProperty !== null || $key->emptyValue !== null) {
                            continue;
                        }
                        $key->configureRequiredTable($schema->getTable($metadata->getTableName()), $platform, $metadata, $property);
                        $this->extraSql[$metadata->getTableName()][] = $key->requiredCheckSql($platform, $metadata, $property);
                    }
                }
            }
        } finally {
            $connection->close();
        }
    }

}
