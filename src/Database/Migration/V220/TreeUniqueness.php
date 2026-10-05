<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;

/** NULL parents still share a single root-level sibling namespace. */
final class TreeUniqueness
{
    public const TABLES = [
        'glpi_businesscriticities' => ['businesscriticities_id', 'name'],
        'glpi_documentcategories' => ['documentcategories_id', 'name'],
        'glpi_knowbaseitemcategories' => ['entities_id', 'knowbaseitemcategories_id', 'name'],
        'glpi_locations' => ['entities_id', 'locations_id', 'name'],
        'glpi_states' => ['states_id', 'name'],
    ];

    public static function indexName(string $table, AbstractPlatform $platform): string
    {
        return $platform instanceof PostgreSQLPlatform ? $table . '_unicity' : 'unicity';
    }

    public static function addToTable(Table $table, AbstractPlatform $platform): void
    {
        $name = $table->getName();
        $parent = substr($name, 5) . '_id';
        // Entity ownership must not depend on an index replaced during migration.
        if (in_array('entities_id', self::TABLES[$name], true) && !$table->hasIndex($name . '_tree_entities')) {
            $table->addIndex(['entities_id'], $name . '_tree_entities');
        }
        if (!$table->hasColumn('parent_key')) {
            $table->addColumn('parent_key', 'bigint', ['notnull' => false, 'columnDefinition' => 'BIGINT GENERATED ALWAYS AS (COALESCE(' . $parent . ', 0)) STORED']);
        }
        $columns = array_map(static fn ($column) => $column === $parent ? 'parent_key' : $column, self::TABLES[$name]);
        $indexName = self::indexName($name, $platform);
        if ($table->hasIndex($indexName)) {
            $index = $table->getIndex($indexName);
            $existing = array_map(static fn ($column) => trim($column, '`'), $index->getColumns());
            if ($index->isUnique() && $existing === $columns) {
                return;
            }
            if (!$index->isUnique() || $existing !== self::TABLES[$name]) {
                throw new \RuntimeException('Unexpected tree uniqueness definition: ' . $name);
            }
            $table->dropIndex($indexName);
        }
        $table->addUniqueIndex($columns, $indexName);
    }

    public function plan(Connection $connection): array
    {
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $sql = [];
        foreach (self::TABLES as $table => $columns) {
            $parent = substr($table, 5) . '_id';
            $group = array_map(static fn ($column) => $column === $parent ? 'COALESCE(' . $quote($column) . ', 0)' : $quote($column), $columns);
            $duplicates = $connection->fetchOne('SELECT COUNT(*) FROM (SELECT 1 FROM ' . $quote($table) . ' WHERE name IS NOT NULL GROUP BY ' . implode(', ', $group) . ' HAVING COUNT(*) > 1) duplicates');
            if ($duplicates) {
                throw new \RuntimeException('Duplicate tree siblings: ' . $table);
            }
            $before = $manager->introspectTable($table);
            $after = clone $before;
            self::addToTable($after, $platform);
            $supporting = $table . '_tree_entities';
            if ($after->hasIndex($supporting) && !$before->hasIndex($supporting)) {
                $sql[] = $platform->getCreateIndexSQL($after->getIndex($supporting), $table);
                $before->addIndex(['entities_id'], $supporting);
            }
            array_push($sql, ...$platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)));
        }
        return $sql;
    }
}
