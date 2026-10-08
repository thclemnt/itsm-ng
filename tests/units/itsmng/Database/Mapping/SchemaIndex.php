<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Mapping;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use ReflectionClass;
use atoum\atoum\test;
use itsmng\Database\BaselineSchema;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\SchemaIndex as OwnedIndex;
use itsmng\Database\Migration\V220\ActorUniqueness;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\Migration\V220\TreeUniqueness;
use itsmng\Database\Orm;
use tests\fixtures\DisconnectedSchemaConnection;

require_once dirname(__DIR__, 4) . '/fixtures/DisconnectedSchemaConnection.php';

class SchemaIndex extends test
{
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
                $this->boolean($ownedGeneratedKeys === $expectedGeneratedKeys)->isTrue('Only twelve actor identity properties and five existing tree keys own generated index columns');
                foreach (ActorUniqueness::TABLES as $table => [$parentKey, $actorKey]) {
                    $entity = $schemaEm->getClassMetadata(EntityRegistry::tables()[$table]);
                    $attributes = (new ReflectionClass($entity->name))->getAttributes(OwnedIndex::class);
                    $this->boolean(count($attributes) === 2)->isTrue('Each actor entity owns its unique and supporting physical indexes: ' . $table);
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
}
