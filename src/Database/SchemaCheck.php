<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use RuntimeException;

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
    public function differences(Connection $connection, ?Schema $expected = null, array $nativeIndexPolicies = [], array $nonNegativePolicies = []): array
    {
        return $this->inspect($connection, $expected, $nativeIndexPolicies, $nonNegativePolicies)->differences;
    }

    /** Inspect current native definitions once without retaining a schema cache. */
    public function inspect(Connection $connection, ?Schema $expected = null, array $nativeIndexPolicies = [], array $nonNegativePolicies = []): SchemaInspection
    {
        $subjectPolicies = [];
        if ($expected === null) {
            $owner = new BaselineSchema();
            $expected = $owner->build($connection->getDatabasePlatform());
            $subjectPolicies = $owner->subjectPolicies();
            $nativeIndexPolicies = $owner->nativeIndexPolicies();
            $nonNegativePolicies = $owner->nonNegativePolicies();
        }
        $manager = $connection->createSchemaManager();
        $actual = $manager->introspectSchema();
        $comparator = $manager->createComparator();
        $differences = [];
        $nativeTypes = [];
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // DBAL introspects both TIMESTAMP and DATETIME as datetime. Their
            // different timezone behavior must not disappear from this check.
            foreach ($connection->fetchAllAssociative(
                'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            ) as $column) {
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
            // Native index coverage is compared below. Table introspection adds
            // synthetic FK indexes that are not evidence of physical storage.
            // Changing an existing declaration's uniqueness alters legal rows;
            // lookup coverage by a stronger index does not authorize that change.
            foreach ($diff->getModifiedIndexes() as $index) {
                $declared = $table->hasIndex($index->getName()) ? $table->getIndex($index->getName()) : $table->getPrimaryKey();
                if ($declared !== null && ($declared->isUnique() !== $index->isUnique() || $declared->isPrimary() !== $index->isPrimary())) {
                    $differences[] = 'Changed index: ' . $name . '.' . $index->getName();
                }
            }
            foreach ($diff->getDroppedForeignKeys() as $key) {
                $differences[] = 'Missing or changed foreign key: ' . $name . '.' . $key->getName();
            }
            foreach ($diff->getAddedForeignKeys() as $key) {
                $differences[] = 'Unexpected or changed foreign key: ' . $name . '.' . $key->getName();
            }
        }
        $checkSnapshot = null;
        $checkDiagnostics = [];
        try {
            $checkSnapshot = NativeCheckCatalog::snapshot($connection);
        } catch (RuntimeException $error) {
            // Retain each current family's existing capability diagnostic;
            // failed ownership inspection cannot be mistaken for empty policy.
            $checkDiagnostics[] = 'Boolean domain enforcement unavailable: ' . $error->getMessage();
            if ($subjectPolicies) {
                $checkDiagnostics[] = 'Native subject enforcement unavailable: ' . $error->getMessage();
            }
        }
        return new SchemaInspection($actual, [
            ...$differences,
            ...$checkDiagnostics,
            ...($checkSnapshot === null ? [] : BooleanDomainSchema::differences($connection, $expected, $checkSnapshot)),
            ...NativeTimestampSchema::differences($connection, $expected),
            ...($checkSnapshot === null ? [] : NativeSubjectSchema::differences($connection, $subjectPolicies, $checkSnapshot)),
            ...($checkSnapshot === null ? [] : NativeNonNegativeSchema::compare($nonNegativePolicies, $checkSnapshot['checks'])),
            ...PhysicalIndexSchema::differences($connection, $expected, $nativeIndexPolicies),
        ]);
    }
}
