<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;

/** Read-only comparison of the required core schema with DBAL introspection. */
final class SchemaCheck
{
    /**
     * Additional tables and indexes are allowed for plugins and local tuning.
     * Boolean domains and declared timestamp touch have native inspectors. Other
     * platform-specific expressions, triggers and CHECKs are not compared by DBAL.
     *
     * @return list<string>
     */
    public function differences(Connection $connection, ?Schema $expected = null): array
    {
        return $this->inspect($connection, $expected)->differences;
    }

    /** Inspect current native definitions once without retaining a schema cache. */
    public function inspect(Connection $connection, ?Schema $expected = null): SchemaInspection
    {
        $expected ??= (new BaselineSchema())->build($connection->getDatabasePlatform());
        $manager = $connection->createSchemaManager();
        $actual = $manager->introspectSchema();
        $comparator = $manager->createComparator();
        $differences = [];
        $nativeTypes = [];
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // DBAL introspects both TIMESTAMP and DATETIME as datetime. Their
            // different timezone behavior must not disappear from this check.
            foreach ($connection->fetchAllAssociative('SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $column) {
                $nativeTypes[$column['table_name']][$column['column_name']] = $column['data_type'];
            }
        }
        foreach ($expected->getTables() as $table) {
            $name = $table->getName();
            if (!$actual->hasTable($name)) {
                $differences[] = 'Missing table: ' . $name;
                continue;
            }
            $diff = $comparator->compareTables($table, $actual->getTable($name));
            foreach ($table->getColumns() as $column) {
                if (str_starts_with($column->getColumnDefinition() ?? '', 'TIMESTAMP')
                    && isset($nativeTypes[$name][$column->getName()])
                    && $nativeTypes[$name][$column->getName()] !== 'timestamp') {
                    $differences[] = 'Expected native TIMESTAMP: ' . $name . '.' . $column->getName();
                }
            }
            foreach ($diff->getDroppedColumns() as $column) {
                $differences[] = 'Missing column: ' . $name . '.' . $column->getName();
            }
            foreach ($diff->getAddedColumns() as $column) {
                $differences[] = 'Unexpected column: ' . $name . '.' . $column->getName();
            }
            foreach ($diff->getChangedColumns() as $column) {
                $differences[] = 'Changed column: ' . $name . '.' . $column->getOldColumn()->getName();
            }
            // Equivalent indexes may have provider-generated names. A rename
            // alone is harmless; missing indexes and changed definitions are not.
            foreach ($diff->getDroppedIndexes() as $index) {
                $differences[] = 'Missing index: ' . $name . '.' . $index->getName();
            }
            foreach ($diff->getModifiedIndexes() as $index) {
                $differences[] = 'Changed index: ' . $name . '.' . $index->getName();
            }
            foreach ($diff->getDroppedForeignKeys() as $key) {
                $differences[] = 'Missing or changed foreign key: ' . $name . '.' . $key->getName();
            }
            foreach ($diff->getAddedForeignKeys() as $key) {
                $differences[] = 'Unexpected or changed foreign key: ' . $name . '.' . $key->getName();
            }
        }
        return new SchemaInspection($actual, [...$differences, ...BooleanDomainSchema::differences($connection, $expected), ...NativeTimestampSchema::differences($connection, $expected)]);
    }
}
