<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Mapping;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use ReflectionClass;
use atoum\atoum\test;
use itsmng\Database\BaselineSchema;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Entity\Group;
use itsmng\Database\Entity\RuleAction;
use itsmng\Database\Mapping\SchemaIndex as OwnedIndex;
use itsmng\Database\Migration\V220\ActorUniqueness;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\TreeUniqueness;
use itsmng\Database\Orm;
use tests\fixtures\DisconnectedSchemaConnection;

require_once dirname(__DIR__, 4) . '/fixtures/DisconnectedSchemaConnection.php';

class SchemaIndex extends test
{
    public function testOnePrefixTupleOwnsBothProviderPolicies(): void
    {
        foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
            $connection = new DisconnectedSchemaConnection($platform);
            try {
                $manager = new EntityManager($connection, Orm::configuration($platform));
                $metadata = $manager->getClassMetadata(RuleAction::class);
                $prefixes = array_filter((new ReflectionClass(RuleAction::class))->getAttributes(OwnedIndex::class),
                    static fn ($attribute): bool => $attribute->newInstance()->prefixLengths !== null);
                $this->integer(count($prefixes))->isIdenticalTo(1);
                $prefix = reset($prefixes)->newInstance();
                $owner = new BaselineSchema($manager);
                $owner->build($platform);
                if ($platform instanceof PostgreSQLPlatform) {
                    $expected = [
                        'glpi_groups' => [
                            'glpi_groups_ldap_value' => ['columns' => ['ldap_value'], 'sourceTypes' => ['text'], 'lengths' => [200]],
                            'glpi_groups_ldap_group_dn' => ['columns' => ['ldap_group_dn'], 'sourceTypes' => ['text'], 'lengths' => [200]],
                        ],
                        'glpi_ruleactions' => ['glpi_ruleactions_field_value' => ['columns' => ['field', 'value'], 'sourceTypes' => ['varchar', 'varchar'], 'lengths' => [50, 50]]],
                    ];
                    $actual = $owner->nativeIndexPolicies();
                    ksort($actual);
                    ksort($expected);
                    $this->array($actual)->isIdenticalTo($expected);
                    $this->boolean(isset($metadata->table['indexes']['glpi_ruleactions_field_value']))->isFalse();
                } else {
                    $this->array($owner->nativeIndexPolicies())->isEmpty();
                    $this->array($metadata->table['indexes']['field_value']['options']['lengths'])->isIdenticalTo([50, 50]);
                }
                $groupMetadata = $manager->getClassMetadata(Group::class);
                foreach (['ldap_value', 'ldap_group_dn'] as $column) {
                    $name = $platform instanceof PostgreSQLPlatform ? 'glpi_groups_' . $column : $column;
                    if ($platform instanceof PostgreSQLPlatform) {
                        $this->boolean(isset($groupMetadata->table['indexes'][$name]))->isFalse();
                    } else {
                        $this->array($groupMetadata->table['indexes'][$name]['options']['lengths'])->isIdenticalTo([200]);
                        $this->array($groupMetadata->table['indexes'][$name]['columns'])->isIdenticalTo([$column]);
                    }
                }
                foreach ([
                    new OwnedIndex('bad', ['field', 'value'], prefixLengths: [50]),
                    new OwnedIndex('bad', ['field', 'field'], prefixLengths: [50, 50]),
                    new OwnedIndex('bad', ['field', 'value'], prefixLengths: [0, 50]),
                    new OwnedIndex('bad', ['field', 'value'], prefixLengths: ['50', 50]),
                    new OwnedIndex('bad', ['field', 'value'], prefixLengths: [256, 50]),
                    new OwnedIndex('bad', ['rules_id'], prefixLengths: [50]),
                    new OwnedIndex('bad', ['field'], unique: true, prefixLengths: [50]),
                    new OwnedIndex('bad', ['field'], options: ['where' => 'true'], prefixLengths: [50]),
                    new OwnedIndex('bad', ['field'], platform: AbstractMySQLPlatform::class, prefixLengths: [50]),
                ] as $invalid) {
                    // Validation uses the PostgreSQL policy directly; a platform filter
                    // cannot silently exclude an ambiguous cross-provider declaration.
                    $this->exception(static fn () => $invalid->nativePrefixPolicy($metadata, new PostgreSQLPlatform()))
                        ->isInstanceOf(LogicException::class);
                }
                $this->boolean($connection->isConnected())->isFalse();
            } finally {
                $connection->close();
            }
        }
    }

    public function testActorAndTreeGeneratedKeysRetainFrozenOwnershipAcrossPlatforms(): void
    {
        $expectedGeneratedKeys = [];
        foreach (array_keys(ActorUniqueness::TABLES) as $table) {
            $expectedGeneratedKeys[] = $table . '.actor_key';
            $expectedGeneratedKeys[] = $table . '.actor_email_key';
        }
        foreach (array_keys(TreeUniqueness::TABLES) as $table) {
            $expectedGeneratedKeys[] = $table . '.parent_key';
        }
        sort($expectedGeneratedKeys, SORT_STRING);
        $tables = [...array_keys(ActorUniqueness::TABLES), ...array_keys(TreeUniqueness::TABLES)];
        foreach ([new MySQLPlatform(), new MariaDBPlatform(),
            new PostgreSQLPlatform(), new MySQLPlatform()] as $schemaPlatform) {
            $offlineConnection = new DisconnectedSchemaConnection($schemaPlatform);
            $this->object($offlineConnection->getDatabasePlatform())->isIdenticalTo($schemaPlatform);
            try {
                $schemaEm = new EntityManager($offlineConnection, Orm::configuration($schemaPlatform));
                $metadata = $schemaEm->getMetadataFactory()->getAllMetadata();
                $mapped = (new SchemaTool($schemaEm))->getSchemaFromMetadata($metadata);
                $required = (new BaselineSchema())->build($schemaPlatform, false);
                $historical = (new Baseline())->build($schemaPlatform);
                $ownedGeneratedKeys = [];
                foreach ($metadata as $entity) {
                    if (!in_array($entity->getTableName(), $tables, true)) {
                        continue;
                    }
                    $ownedColumns = [];
                    foreach ((new ReflectionClass($entity->name))->getAttributes(OwnedIndex::class) as $attribute) {
                        array_push($ownedColumns, ...$attribute->newInstance()->columns);
                    }
                    foreach ($entity->fieldMappings as $field) {
                        $column = trim($field->columnName, '`"');
                        if (in_array($column, $ownedColumns, true) && $field->notInsertable && $field->notUpdatable
                            && $field->generated === ClassMetadata::GENERATED_ALWAYS && $field->columnDefinition !== null) {
                            $ownedGeneratedKeys[] = $entity->getTableName() . '.' . $column;
                        }
                    }
                }
                sort($ownedGeneratedKeys, SORT_STRING);
                $this->boolean($ownedGeneratedKeys === $expectedGeneratedKeys)->isTrue('Actor and tree entities retain their twelve actor identity properties and five parent keys');
                foreach (ActorUniqueness::TABLES as $table => [$parentKey, $actorKey]) {
                    $entity = $schemaEm->getClassMetadata(EntityRegistry::tables()[$table]);
                    $attributes = (new ReflectionClass($entity->name))->getAttributes(OwnedIndex::class);
                    $names = array_map(static fn ($attribute): string => $attribute->newInstance()->name($schemaPlatform), $attributes);
                    $this->array($names)->contains(ActorUniqueness::indexName($table, $schemaPlatform))->contains($table . '_actor_parent');
                    $oracle = clone $historical->getTable($table);
                    ActorUniqueness::addToTable($oracle, $schemaPlatform);
                    $unique = ActorUniqueness::indexName($table, $schemaPlatform);
                    foreach ([$mapped->getTable($table), $required->getTable($table)] as $schemaTable) {
                        foreach ([$unique, $table . '_actor_parent'] as $indexName) {
                            $this->boolean($schemaTable->hasIndex($indexName))->isTrue('ORM and current schema retain the exact adopted physical index name: ' . $table . '.' . $indexName);
                            $index = $schemaTable->getIndex($indexName);
                            $frozen = $oracle->getIndex($indexName);
                            $this->boolean($index->isUnique() === $frozen->isUnique() && $index->getColumns() === $frozen->getColumns()
                                && $index->getFlags() === $frozen->getFlags() && $index->getOptions() === $frozen->getOptions())->isTrue('Actor index tuple, uniqueness and options remain frozen: ' . $table . '.' . $indexName);
                        }
                        foreach (['actor_key', 'actor_email_key'] as $key) {
                            $column = $schemaTable->getColumn($key);
                            $frozen = $oracle->getColumn($key);
                            $this->boolean(Type::lookupName($column->getType()) === Type::lookupName($frozen->getType())
                                && $column->getNotnull() === $frozen->getNotnull() && $column->getDefault() === $frozen->getDefault()
                                && $column->getLength() === $frozen->getLength() && $column->getComment() === $frozen->getComment()
                                && $column->getColumnDefinition() === $frozen->getColumnDefinition())->isTrue('Generated property storage and expression equal the frozen adoption definition: ' . $table . '.' . $key);
                        }
                    }
                    foreach (['actor_key', 'actor_email_key'] as $key) {
                        $field = $entity->getFieldMapping($key);
                        $this->boolean($field->notInsertable && $field->notUpdatable && $field->generated === ClassMetadata::GENERATED_ALWAYS
                            && $field->nullable && $field->columnDefinition === $oracle->getColumn($key)->getColumnDefinition())->isTrue('Actor generated property remains nullable, always generated and excluded from writes: ' . $table . '.' . $key);
                    }
                }
                $this->boolean($offlineConnection->isConnected())->isFalse();
            } finally {
                $offlineConnection->close();
                unset($schemaEm, $metadata, $mapped, $required, $historical);
                gc_collect_cycles();
            }
        }
    }
    public function testProviderOwnedIndexOptionsAndExclusion(): void
    {
        $prefix = new OwnedIndex(
            'field_value',
            ['field', 'value'],
            options: ['lengths' => [50, 50]],
            platform: AbstractMySQLPlatform::class
        );
        $shared = new OwnedIndex(
            'shared',
            ['value'],
            postgresqlName: 'glpi_ruleactions_shared',
            options: ['lengths' => [50]],
            postgresqlOptions: ['lengths' => [null]]
        );
        foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
            $metadata = new ClassMetadata(RuleAction::class);
            $prefix->addToMetadata($metadata, $platform);
            $shared->addToMetadata($metadata, $platform);
            $postgres = $platform instanceof PostgreSQLPlatform;
            $this->boolean(isset($metadata->table['indexes']['field_value']))->isEqualTo(!$postgres);
            if (!$postgres) {
                $this->array($metadata->table['indexes']['field_value']['options']['lengths'])->isIdenticalTo([50, 50]);
            }
            $sharedName = $postgres ? 'glpi_ruleactions_shared' : 'shared';
            $this->array($metadata->table['indexes'][$sharedName]['options']['lengths'])
                ->isIdenticalTo($postgres ? [null] : [50]);
        }
        // Existing declarations retain exactly their prior metadata shape.
        $metadata = new ClassMetadata(RuleAction::class);
        (new OwnedIndex('legacy', ['value']))->addToMetadata($metadata, new MySQLPlatform());
        $this->array($metadata->table['indexes']['legacy'])->isIdenticalTo(['columns' => ['value']]);
    }

    public function testRuleActionPrefixIndexUsesTheOwningProvider(): void
    {
        foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
            $connection = new DisconnectedSchemaConnection($platform);
            try {
                $manager = new EntityManager($connection, Orm::configuration($platform));
                $metadata = $manager->getMetadataFactory()->getAllMetadata();
                $mapped = (new SchemaTool($manager))->getSchemaFromMetadata($metadata);
                $table = $mapped->getTable('glpi_ruleactions');
                if ($platform instanceof PostgreSQLPlatform) {
                    $this->boolean($table->hasIndex('field_value'))->isFalse();
                    $this->boolean($table->hasIndex('glpi_ruleactions_field_value'))->isFalse();
                    $this->boolean($table->hasIndex('glpi_ruleactions_rules_id'))->isTrue();
                } else {
                    $index = $table->getIndex('field_value');
                    $this->array($index->getColumns())->isIdenticalTo(['field', 'value']);
                    $this->array($index->getOptions()['lengths'])->isIdenticalTo([50, 50]);
                    $sql = $platform->getCreateIndexSQL($index, 'glpi_ruleactions');
                    $this->boolean(str_contains($sql, 'field(50)') || str_contains($sql, '`field`(50)'))->isTrue();
                    $this->boolean(str_contains($sql, 'value(50)') || str_contains($sql, '`value`(50)'))->isTrue();
                }
                $this->boolean($connection->isConnected())->isFalse();
            } finally {
                $connection->close();
                unset($manager, $metadata, $mapped);
                gc_collect_cycles();
            }
        }
    }

    public function testKnowledgeFullTextIndexesRemainMySQLOwned(): void
    {
        foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
            $connection = new DisconnectedSchemaConnection($platform);
            try {
                $manager = new EntityManager($connection, Orm::configuration($platform));
                $metadata = $manager->getMetadataFactory()->getAllMetadata();
                $mapped = (new SchemaTool($manager))->getSchemaFromMetadata($metadata);
                foreach (['glpi_knowbaseitems', 'glpi_knowbaseitemtranslations'] as $tableName) {
                    $table = $mapped->getTable($tableName);
                    foreach (['fulltext' => ['name', 'answer'], 'name' => ['name'], 'answer' => ['answer']] as $name => $columns) {
                        if ($platform instanceof PostgreSQLPlatform) {
                            $this->boolean($table->hasIndex($name))->isFalse();
                        } else {
                            $index = $table->getIndex($name);
                            $this->array($index->getColumns())->isIdenticalTo($columns);
                            $this->array($index->getFlags())->isIdenticalTo(['fulltext']);
                            $this->boolean(str_contains($platform->getCreateIndexSQL($index, $tableName), 'FULLTEXT'))->isTrue();
                        }
                    }
                }
                $this->boolean($connection->isConnected())->isFalse();
            } finally {
                $connection->close();
                unset($manager, $metadata, $mapped);
                gc_collect_cycles();
            }
        }
    }

}
