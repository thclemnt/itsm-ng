<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;

/** Keep OS/architecture uniqueness when missing dropdowns become NULL. */
final class InventoryUniqueness
{
    public const KEYS = ['operatingsystem_key' => 'operatingsystems_id', 'architecture_key' => 'operatingsystemarchitectures_id'];

    public static function addToTable(Table $table, string $indexName): void
    {
        foreach (self::KEYS as $key => $column) {
            if (!$table->hasColumn($key)) {
                $table->addColumn($key, 'bigint', ['notnull' => false, 'columnDefinition' => 'BIGINT GENERATED ALWAYS AS (COALESCE(' . $column . ', 0)) STORED']);
            }
        }
        $columns = ['items_id', 'itemtype', ...array_keys(self::KEYS)];
        if ($table->hasIndex($indexName)) {
            $index = $table->getIndex($indexName);
            $existing = array_map(static fn ($column) => trim($column, '`'), $index->getColumns());
            if ($index->isUnique() && $existing === $columns) {
                return;
            }
            if (!$index->isUnique() || $existing !== ['items_id', 'itemtype', 'operatingsystems_id', 'operatingsystemarchitectures_id']) {
                throw new \RuntimeException('Unexpected OS uniqueness definition: ' . $indexName);
            }
            $table->dropIndex($indexName);
        }
        $table->addUniqueIndex($columns, $indexName);
    }

    public static function indexName(AbstractPlatform $platform): string
    {
        return $platform instanceof PostgreSQLPlatform ? 'glpi_items_operatingsystems_unicity' : 'unicity';
    }


    public function assertUniqueAssignments(Connection $connection, string $identity = 'items_id'): void
    {
        // The frozen owner expression also audits recoverable schemas whose
        // compatibility column is absent. It never follows runtime metadata.
        $duplicates = $connection->fetchAllAssociative('SELECT ' . $identity . ' AS items_id, itemtype, COALESCE(operatingsystems_id, 0) AS os, COALESCE(operatingsystemarchitectures_id, 0) AS architecture, COUNT(*) AS duplicates FROM glpi_items_operatingsystems WHERE itemtype IS NOT NULL GROUP BY ' . $identity . ', itemtype, COALESCE(operatingsystems_id, 0), COALESCE(operatingsystemarchitectures_id, 0) HAVING COUNT(*) > 1');
        if ($duplicates) {
            throw new \RuntimeException('Duplicate OS/architecture assignments: ' . json_encode($duplicates));
        }
    }

    public function plan(Connection $connection): array
    {
        $this->assertUniqueAssignments($connection);
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable('glpi_items_operatingsystems');
        $after = clone $before;
        self::addToTable($after, self::indexName($connection->getDatabasePlatform()));
        return $connection->getDatabasePlatform()->getAlterTableSQL($manager->createComparator()->compareTables($before, $after));
    }
}
