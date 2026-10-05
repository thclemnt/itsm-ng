<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;

/** Preserve shared defaults' unique identity when their absent owner becomes NULL. */
final class SharedOwnerUniqueness
{
    private const TABLES = [
        'glpi_items_kanbans' => ['columns' => ['itemtype', 'items_id', 'users_id'], 'label' => 'Kanban owner states'],
        'glpi_displaypreferences' => ['columns' => ['users_id', 'itemtype', 'num'], 'label' => 'display preference owners'],
    ];

    public static function addToTable(Table $table, AbstractPlatform $platform): void
    {
        $definition = self::TABLES[$table->getName()];
        if (!$table->hasColumn('owner_key')) {
            $table->addColumn('owner_key', 'bigint', ['notnull' => false, 'columnDefinition' => 'BIGINT GENERATED ALWAYS AS (COALESCE(users_id, 0)) STORED']);
        }
        $name = $platform instanceof PostgreSQLPlatform ? $table->getName() . '_unicity' : 'unicity';
        $columns = array_map(static fn ($column) => $column === 'users_id' ? 'owner_key' : $column, $definition['columns']);
        if ($table->hasIndex($name)) {
            $index = $table->getIndex($name);
            $existing = array_map(static fn ($column) => trim($column, '`'), $index->getColumns());
            if ($index->isUnique() && $existing === $columns) {
                return;
            }
            if (!$index->isUnique() || $existing !== $definition['columns']) {
                throw new \RuntimeException('Unexpected shared owner uniqueness definition: ' . $table->getName());
            }
            $table->dropIndex($name);
        }
        $table->addUniqueIndex($columns, $name);
    }

    public function plan(Connection $connection, string $table): array
    {
        $definition = self::TABLES[$table];
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $columns = array_map(static fn ($column) => $column === 'users_id' ? 'COALESCE(users_id, 0)' : $quote($column), $definition['columns']);
        $notNull = array_map(static fn ($column) => $quote($column) . ' IS NOT NULL', array_filter($definition['columns'], static fn ($column) => $column !== 'users_id'));
        $group = implode(', ', $columns);
        if ($connection->fetchOne('SELECT COUNT(*) FROM (SELECT ' . $group . ' FROM ' . $quote($table) . ' WHERE ' . implode(' AND ', $notNull) . ' GROUP BY ' . $group . ' HAVING COUNT(*) > 1) duplicates')) {
            throw new \RuntimeException('Duplicate ' . $definition['label'] . '; reconcile them before migration.');
        }
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable($table);
        $after = clone $before;
        self::addToTable($after, $platform);
        return $platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after));
    }
}
