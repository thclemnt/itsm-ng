<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use atoum\atoum\test;
use itsmng\Database\BaselineSchema;
use itsmng\Database\Entity\Alert;
use itsmng\Database\Entity\Computer;
use itsmng\Database\Entity\Domain;
use itsmng\Database\Entity\ObjectLock;
use itsmng\Database\Mapping\AttributeDriver;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Migration\V220\Baseline;
use itsmng\Database\NativeTimestampSchema as Policy;
use itsmng\Database\Orm;
use Doctrine\ORM\Mapping\ClassMetadata;
use itsmng\Database\EntityRegistry;
use tests\fixtures\DisconnectedSchemaConnection;
use InvalidArgumentException;
use NativeTemporalProbe;
use ReflectionProperty;

require_once dirname(__DIR__, 3) . '/fixtures/DisconnectedSchemaConnection.php';
require_once dirname(__DIR__, 3) . '/fixtures/NativeTemporalProbe.php';

class NativeTimestampSchema extends test
{
    public function testInitialTemporalCohortOwnsStorageReplacementAndTouchDdl(): void
    {
        $cohort = [Computer::class => ['date_mod', 'date_creation'], Alert::class => ['date'], Domain::class => ['date_expiration', 'date_mod', 'date_creation'], ObjectLock::class => ['date_mod']];
        foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
            $connection = new DisconnectedSchemaConnection($platform);
            $this->object($connection->getDatabasePlatform())->isIdenticalTo($platform);
            try {
                $em = new EntityManager($connection, Orm::configuration($platform));
                $current = (new BaselineSchema())->build($platform, false);
                $historical = (new Baseline())->build($platform);
                // This original contract owns its explicit seven-property cohort. The
                // coverage contract independently checks the complete frozen/property set.
                $declarations = Policy::declarations(array_map($em->getClassMetadata(...), array_keys($cohort)));
                $this->boolean(array_sum(array_map('count', $declarations)) === 7)->isTrue('Exactly the first seven temporal properties own native storage');
                foreach ($cohort as $class => $properties) {
                    $metadata = $em->getClassMetadata($class);
                    $mapped = (new SchemaTool($em))->getSchemaFromMetadata([$metadata])->getTable($metadata->getTableName());
                    foreach ($properties as $property) {
                        $field = $metadata->getFieldMapping($property);
                        $column = $mapped->getColumn($field->columnName);
                        $this->boolean($field->type === Types::DATETIMETZ_MUTABLE && !$metadata->isVersioned && $metadata->versionField === null)->isTrue('Existing datetime hydration survives without an optimistic version field');
                        if ($class === ObjectLock::class) {
                            $this->boolean(
                                $field->generated === ClassMetadata::GENERATED_ALWAYS && !$field->notInsertable && !$field->notUpdatable
                                && !in_array('date_mod', EntityRegistry::readOnlyColumns($metadata->getTableName()), true)
                            )->isTrue('The automatic clock refreshes its managed outcome while keeping explicit writes enabled');
                        }
                        $this->boolean($platform instanceof AbstractMySQLPlatform ? str_starts_with($column->getColumnDefinition(), 'TIMESTAMP ') : $column->getColumnDefinition() === null)->isTrue('SchemaTool obtains native storage from the property without historical input');
                        foreach ([$current->getTable($metadata->getTableName())->getColumn($field->columnName), $historical->getTable($metadata->getTableName())->getColumn($field->columnName)] as $expected) {
                            // The frozen Domain expiration declaration contains two
                            // spaces; SQL whitespace is not a different temporal policy.
                            $physical = static fn ($value): string => preg_replace('/\s+/', ' ', trim((string)$value));
                            $this->boolean(
                                $expected->getNotnull() === $column->getNotnull()
                                && $platform->getDefaultValueDeclarationSQL($expected->toArray(true)) === $platform->getDefaultValueDeclarationSQL($column->toArray(true))
                                && $expected->getComment() === $column->getComment() && $physical($expected->getColumnDefinition()) === $physical($column->getColumnDefinition())
                            )->isTrue('Current property projection converges on the retained historical column semantics');
                        }
                    }
                }
                $config = Orm::configuration($platform);
                $config->setMetadataDriverImpl(new AttributeDriver([], $platform));
                $probeEm = new EntityManager($connection, $config);
                $probeMetadata = $probeEm->getClassMetadata(NativeTemporalProbe::class);
                $probe = (new SchemaTool($probeEm))->getSchemaFromMetadata([$probeMetadata])->getTable('glpi_native_temporal_probe');
                $policies = Policy::declarations([$probeMetadata])['glpi_native_temporal_probe'];
                $stale = clone $probe;
                $stale->modifyColumn('nullable_instant', ['typeName' => Types::STRING, 'notnull' => true, 'default' => '2001-01-01 00:00:00', 'comment' => 'Old storage', 'columnDefinition' => 'DATETIME NOT NULL']);
                $stale->modifyColumn('unmarked_wall_time', ['comment' => 'Unmarked historical detail']);
                $stale->addIndex(['nullable_instant'], 'retained_temporal_index');
                Policy::replaceOwnedColumns($stale, $probe, $policies);
                $this->boolean(
                    $stale->getColumn('nullable_instant')->getTypeName() === Types::DATETIMETZ_MUTABLE
                    && !$stale->getColumn('nullable_instant')->getNotnull() && $stale->getColumn('nullable_instant')->getDefault() === null
                    && $stale->getColumn('nullable_instant')->getComment() === "Property's instant"
                    && $stale->getColumn('nullable_instant')->getColumnDefinition() === $probe->getColumn('nullable_instant')->getColumnDefinition()
                )->isTrue('Property metadata replaces a stale existing temporal definition, including default/null/comment');
                $this->boolean($stale->hasIndex('retained_temporal_index') && $stale->getColumn('unmarked_wall_time')->getComment() === 'Unmarked historical detail')->isTrue('Owned temporal replacement preserves supporting indexes and unmarked historical fields');
                $this->boolean($probe->getColumn('unmarked_wall_time')->getColumnDefinition() === null)->isTrue('Unmarked datetimetz is not globally rewritten');
                $field = clone $probeMetadata->getFieldMapping('nullable_instant');
                $field->type = Types::INTEGER;
                $refused = false;
                try {
                    (new NativeTimestamp())->declaration($platform, $field);
                } catch (InvalidArgumentException) {
                    $refused = true;
                }
                $this->boolean($refused)->isTrue('An incompatible scalar cannot be labelled as a native temporal property');
                $lock = $em->getClassMetadata(ObjectLock::class);
                $policy = $declarations[$lock->getTableName()]['date_mod'];
                $sql = (new BaselineSchema())->toSql($platform, false);
                foreach ($policy->touchSql($platform, $lock->getTableName(), 'date_mod') as $statement) {
                    $this->boolean(count(array_filter($sql, static fn ($candidate) => $candidate === $statement)) === 1)->isTrue('Current touch DDL is emitted once from the property, replacing its inherited historical definition');
                }
                $this->boolean($connection->isConnected())->isFalse();
            } finally {
                $connection->close();
            }
        }
    }

    public function testEveryFrozenTimestampHasExactlyOneMatchingPropertyAndClockPolicy(): void
    {
        // A historical test oracle is legitimate. Runtime declarations never consult it.
        $oracle = [];
        foreach ((new Baseline())->build(new MySQLPlatform())->getTables() as $table) {
            foreach ($table->getColumns() as $column) {
                if (preg_match('/^TIMESTAMP(?:\([0-9]+\))?\s/iD', (string)$column->getColumnDefinition()) === 1) {
                    $oracle[$table->getName()][$column->getName()] = $column;
                }
            }
        }
        $this->boolean($oracle !== [])->isTrue('Frozen native timestamp oracle must not be empty');
        $expectedKeys = [];
        foreach ($oracle as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $expectedKeys[] = $table . '.' . $column;
            }
        }
        sort($expectedKeys, SORT_STRING);
        $physical = static fn (?string $sql): string => preg_replace('/\s+/', ' ', trim((string)$sql));

        foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
            $connection = new DisconnectedSchemaConnection($platform);
            $this->object($connection->getDatabasePlatform())->isIdenticalTo($platform);
            try {
                $em = new EntityManager($connection, Orm::configuration($platform));
                $metadata = $em->getMetadataFactory()->getAllMetadata();
                $byTable = [];
                foreach ($metadata as $entity) {
                    $this->boolean(!isset($byTable[$entity->getTableName()]))->isTrue('Each core temporal table has one mapped entity owner');
                    $byTable[$entity->getTableName()] = $entity;
                }
                $declarations = Policy::declarations($metadata);
                $actualKeys = [];
                foreach ($declarations as $table => $columns) {
                    foreach ($columns as $column => $policy) {
                        $actualKeys[] = $table . '.' . $column;
                    }
                }
                sort($actualKeys, SORT_STRING);
                $this->boolean($actualKeys === $expectedKeys)->isTrue('The complete property-owned set exactly matches frozen native instants: no omissions or accidental wall-time annotations');
                $mapped = (new SchemaTool($em))->getSchemaFromMetadata($metadata);
                $current = (new BaselineSchema())->build($platform, false);
                $historical = (new Baseline())->build($platform);
                $touchKeys = [];
                foreach ($oracle as $table => $columns) {
                    $this->boolean(isset($byTable[$table]))->isTrue('Every frozen timestamp has a real current entity owner: ' . $table);
                    $entity = $byTable[$table];
                    foreach ($columns as $column => $frozenNative) {
                        $fields = array_filter($entity->fieldMappings, static fn ($field): bool => trim($field->columnName, '`"') === $column);
                        $this->boolean(count($fields) === 1)->isTrue('Every timestamp resolves to exactly one property: ' . $table . '.' . $column);
                        $property = array_key_first($fields);
                        $field = $fields[$property];
                        $reflection = new ReflectionProperty($entity->name, $property);
                        $this->boolean(count($reflection->getAttributes(NativeTimestamp::class)) === 1)->isTrue('Storage is declared exactly once beside the property');
                        $this->boolean($field->type === Types::DATETIMETZ_MUTABLE && !$field->notInsertable && !$field->notUpdatable)->isTrue('Existing mutable instant hydration and explicit write eligibility remain unchanged');
                        $policy = $declarations[$table][$column];
                        $touch = str_contains((string)$frozenNative->getColumnDefinition(), ' ON UPDATE CURRENT_TIMESTAMP');
                        $this->boolean(($policy->touchTrigger !== null) === $touch)->isTrue('Automatic touch exists only where the frozen native policy already owns it');
                        if ($touch) {
                            $touchKeys[] = $table . '.' . $column;
                            $this->boolean($field->generated === ClassMetadata::GENERATED_ALWAYS && $policy->ownsWritableClock($field))->isTrue('The existing automatic clock retains writable generated outcome ownership');
                        } else {
                            $this->boolean(in_array($field->generated, [null, ClassMetadata::GENERATED_NEVER], true) && !$policy->ownsWritableClock($field))->isTrue('An ordinary native timestamp does not acquire automatic touch or generated readback');
                        }
                        $owned = $mapped->getTable($table)->getColumn($column);
                        $this->boolean($platform instanceof AbstractMySQLPlatform
                            ? preg_match('/^TIMESTAMP(?:\([0-9]+\))?\s/iD', (string)$owned->getColumnDefinition()) === 1
                            : $owned->getColumnDefinition() === null)->isTrue('SchemaTool obtains native MySQL storage from current properties while PostgreSQL retains ordinary datetimetz');
                        foreach ([$historical->getTable($table)->getColumn($column), $current->getTable($table)->getColumn($column)] as $expected) {
                            $this->boolean($expected->getTypeName() === $owned->getTypeName()
                                && $expected->getNotnull() === $owned->getNotnull()
                                && $expected->getLength() === $owned->getLength()
                                && $expected->getPrecision() === $owned->getPrecision()
                                && $expected->getScale() === $owned->getScale()
                                && $expected->getUnsigned() === $owned->getUnsigned()
                                && $expected->getFixed() === $owned->getFixed()
                                && $expected->getAutoincrement() === $owned->getAutoincrement()
                                && $expected->getComment() === $owned->getComment()
                                && $platform->getDefaultValueDeclarationSQL($expected->toArray(true)) === $platform->getDefaultValueDeclarationSQL($owned->toArray(true))
                                && $physical($expected->getColumnDefinition()) === $physical($owned->getColumnDefinition()))->isTrue('Frozen, entity-only and current schemas retain every timestamp storage option: ' . $table . '.' . $column);
                        }
                    }
                }
                $expectedTouches = [];
                foreach ($oracle as $table => $columns) {
                    foreach ($columns as $column => $definition) {
                        if (str_contains((string)$definition->getColumnDefinition(), ' ON UPDATE CURRENT_TIMESTAMP')) {
                            $expectedTouches[] = $table . '.' . $column;
                        }
                    }
                }
                sort($touchKeys, SORT_STRING);
                sort($expectedTouches, SORT_STRING);
                $this->boolean($touchKeys === $expectedTouches)->isTrue('The complete native automatic-clock set remains exactly historical');

                // Independent, explicitly unmarked property: not a now-intentionally marked core field.
                $configuration = Orm::configuration($platform);
                $configuration->setMetadataDriverImpl(new AttributeDriver([], $platform));
                $probeEm = new EntityManager($connection, $configuration);
                $probeMetadata = $probeEm->getClassMetadata(NativeTemporalProbe::class);
                $probe = (new SchemaTool($probeEm))->getSchemaFromMetadata([$probeMetadata]);
                $wall = $probe->getTable('glpi_native_temporal_probe')->getColumn('unmarked_wall_time');
                $this->boolean($wall->getTypeName() === Types::DATETIMETZ_MUTABLE && $wall->getColumnDefinition() === null
                    && !isset(Policy::declarations([$probeMetadata])['glpi_native_temporal_probe']['unmarked_wall_time']))->isTrue('Unmarked probe datetime preserves its ordinary type and never enters the native-instant set');
                if ($platform instanceof AbstractMySQLPlatform) {
                    $sql = implode("\n", $probe->toSql($platform));
                    $this->boolean(preg_match('/\bunmarked_wall_time\b`?\s+DATETIME\b/i', $sql) === 1)->isTrue('The actual SchemaTool probe DDL keeps unmarked MySQL wall-time storage as DATETIME');
                }
                $this->boolean($connection->isConnected())->isFalse();
            } finally {
                $connection->close();
            }
        }
    }
}
