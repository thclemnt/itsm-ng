<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use itsmng\Database\BaselineSchema;
use itsmng\Database\Mapping\AttributeDriver;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Migration\Baseline20261001;
use itsmng\Database\NativeTimestampSchema;
use itsmng\Database\Orm;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/fixtures/NativeTemporalProbe.php';

$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

// A historical test oracle is legitimate. Runtime declarations never consult it.
$oracle = [];
foreach ((new Baseline20261001())->build(new MySQLPlatform())->getTables() as $table) {
    foreach ($table->getColumns() as $column) {
        if (preg_match('/^TIMESTAMP(?:\([0-9]+\))?\s/iD', (string)$column->getColumnDefinition()) === 1) {
            $oracle[$table->getName()][$column->getName()] = $column;
        }
    }
}
verify($oracle !== [], 'Frozen native timestamp oracle must not be empty');
$expectedKeys = [];
foreach ($oracle as $table => $columns) {
    foreach ($columns as $column => $definition) {
        $expectedKeys[] = $table . '.' . $column;
    }
}
sort($expectedKeys, SORT_STRING);
$physical = static fn (?string $sql): string => preg_replace('/\s+/', ' ', trim((string)$sql));

foreach ([new MySQLPlatform(), new MariaDBPlatform(), new PostgreSQLPlatform()] as $platform) {
    $connection = DriverManager::getConnection($platform instanceof PostgreSQLPlatform
        ? ['driver' => 'pdo_pgsql', 'serverVersion' => '16.0']
        : ['driver' => 'pdo_mysql', 'serverVersion' => $platform instanceof MariaDBPlatform ? '10.11.0-MariaDB' : '8.4.0']);
    try {
        $em = new EntityManager($connection, Orm::configuration($platform));
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $byTable = [];
        foreach ($metadata as $entity) {
            verify(!isset($byTable[$entity->getTableName()]), 'Each core temporal table has one mapped entity owner');
            $byTable[$entity->getTableName()] = $entity;
        }
        $declarations = NativeTimestampSchema::declarations($metadata);
        $actualKeys = [];
        foreach ($declarations as $table => $columns) {
            foreach ($columns as $column => $policy) {
                $actualKeys[] = $table . '.' . $column;
            }
        }
        sort($actualKeys, SORT_STRING);
        verify($actualKeys === $expectedKeys, 'The complete property-owned set exactly matches frozen native instants: no omissions or accidental wall-time annotations');
        $mapped = (new SchemaTool($em))->getSchemaFromMetadata($metadata);
        $current = (new BaselineSchema())->build($platform, false);
        $historical = (new Baseline20261001())->build($platform);
        $touchKeys = [];
        foreach ($oracle as $table => $columns) {
            verify(isset($byTable[$table]), 'Every frozen timestamp has a real current entity owner: ' . $table);
            $entity = $byTable[$table];
            foreach ($columns as $column => $frozenNative) {
                $fields = array_filter($entity->fieldMappings, static fn ($field): bool => trim($field->columnName, '`"') === $column);
                verify(count($fields) === 1, 'Every timestamp resolves to exactly one property: ' . $table . '.' . $column);
                $property = array_key_first($fields);
                $field = $fields[$property];
                $reflection = new ReflectionProperty($entity->name, $property);
                verify(count($reflection->getAttributes(NativeTimestamp::class)) === 1, 'Storage is declared exactly once beside the property');
                verify($field->type === Types::DATETIMETZ_MUTABLE && !$field->notInsertable && !$field->notUpdatable,
                    'Existing mutable instant hydration and explicit write eligibility remain unchanged');
                $policy = $declarations[$table][$column];
                $touch = str_contains((string)$frozenNative->getColumnDefinition(), ' ON UPDATE CURRENT_TIMESTAMP');
                verify(($policy->touchTrigger !== null) === $touch, 'Automatic touch exists only where the frozen native policy already owns it');
                if ($touch) {
                    $touchKeys[] = $table . '.' . $column;
                    verify($field->generated === ClassMetadata::GENERATED_ALWAYS && $policy->ownsWritableClock($field),
                        'The existing automatic clock retains writable generated outcome ownership');
                } else {
                    verify(in_array($field->generated, [null, ClassMetadata::GENERATED_NEVER], true) && !$policy->ownsWritableClock($field),
                        'An ordinary native timestamp does not acquire automatic touch or generated readback');
                }
                $owned = $mapped->getTable($table)->getColumn($column);
                verify($platform instanceof AbstractMySQLPlatform
                    ? preg_match('/^TIMESTAMP(?:\([0-9]+\))?\s/iD', (string)$owned->getColumnDefinition()) === 1
                    : $owned->getColumnDefinition() === null,
                    'SchemaTool obtains native MySQL storage from current properties while PostgreSQL retains ordinary datetimetz');
                foreach ([$historical->getTable($table)->getColumn($column), $current->getTable($table)->getColumn($column)] as $expected) {
                    verify($expected->getTypeName() === $owned->getTypeName()
                        && $expected->getNotnull() === $owned->getNotnull()
                        && $expected->getLength() === $owned->getLength()
                        && $expected->getPrecision() === $owned->getPrecision()
                        && $expected->getScale() === $owned->getScale()
                        && $expected->getUnsigned() === $owned->getUnsigned()
                        && $expected->getFixed() === $owned->getFixed()
                        && $expected->getAutoincrement() === $owned->getAutoincrement()
                        && $expected->getComment() === $owned->getComment()
                        && $platform->getDefaultValueDeclarationSQL($expected->toArray(true)) === $platform->getDefaultValueDeclarationSQL($owned->toArray(true))
                        && $physical($expected->getColumnDefinition()) === $physical($owned->getColumnDefinition()),
                        'Frozen, entity-only and current schemas retain every timestamp storage option: ' . $table . '.' . $column);
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
        verify($touchKeys === $expectedTouches, 'The complete native automatic-clock set remains exactly historical');

        // Independent, explicitly unmarked property: not a now-intentionally marked core field.
        $configuration = Orm::configuration($platform);
        $configuration->setMetadataDriverImpl(new AttributeDriver([], $platform));
        $probeEm = new EntityManager($connection, $configuration);
        $probeMetadata = $probeEm->getClassMetadata(NativeTemporalProbe::class);
        $probe = (new SchemaTool($probeEm))->getSchemaFromMetadata([$probeMetadata]);
        $wall = $probe->getTable('glpi_native_temporal_probe')->getColumn('unmarked_wall_time');
        verify($wall->getTypeName() === Types::DATETIMETZ_MUTABLE && $wall->getColumnDefinition() === null
            && !isset(NativeTimestampSchema::declarations([$probeMetadata])['glpi_native_temporal_probe']['unmarked_wall_time']),
            'Unmarked probe datetime preserves its ordinary type and never enters the native-instant set');
        if ($platform instanceof AbstractMySQLPlatform) {
            $sql = implode("\n", $probe->toSql($platform));
            verify(preg_match('/\bunmarked_wall_time\b`?\s+DATETIME\b/i', $sql) === 1,
                'The actual SchemaTool probe DDL keeps unmarked MySQL wall-time storage as DATETIME');
        }
    } finally {
        $connection->close();
    }
}
printf("PASS: %d offline assertions cover %d historical timestamp properties in %d tables on three platforms\n",
    $assertions, count($expectedKeys), count($oracle));
