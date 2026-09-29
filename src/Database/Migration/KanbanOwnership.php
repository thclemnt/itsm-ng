<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\OptionalReferences;

/** NULL means shared board state; its identity must remain unique after migration. */
final class KanbanOwnership
{
    public const VERSION = '20260929_kanban_ownership';

    public static function indexName(AbstractPlatform $platform): string
    {
        return $platform instanceof PostgreSQLPlatform ? 'glpi_items_kanbans_unicity' : 'unicity';
    }

    public static function addToTable(Table $table, AbstractPlatform $platform): void
    {
        if (!$table->hasColumn('owner_key')) {
            $table->addColumn('owner_key', 'bigint', ['notnull' => false, 'columnDefinition' => 'BIGINT GENERATED ALWAYS AS (COALESCE(users_id, 0)) STORED']);
        }
        $name = self::indexName($platform);
        $columns = ['itemtype', 'items_id', 'owner_key'];
        if ($table->hasIndex($name)) {
            $index = $table->getIndex($name);
            $existing = array_map(static fn ($column) => trim($column, '`'), $index->getColumns());
            if ($index->isUnique() && $existing === $columns) {
                return;
            }
            if (!$index->isUnique() || $existing !== ['itemtype', 'items_id', 'users_id']) {
                throw new \RuntimeException('Unexpected Kanban uniqueness definition: ' . $name);
            }
            $table->dropIndex($name);
        }
        $table->addUniqueIndex($columns, $name);
    }

    private function uniquenessPlan(Connection $connection): array
    {
        if ($connection->fetchOne('SELECT COUNT(*) FROM (SELECT itemtype, items_id, COALESCE(users_id, 0) FROM glpi_items_kanbans WHERE items_id IS NOT NULL GROUP BY itemtype, items_id, COALESCE(users_id, 0) HAVING COUNT(*) > 1) duplicates')) {
            throw new \RuntimeException('Duplicate Kanban owner states; reconcile them before migration.');
        }
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable('glpi_items_kanbans');
        $after = clone $before;
        self::addToTable($after, $connection->getDatabasePlatform());
        return $connection->getDatabasePlatform()->getAlterTableSQL($manager->createComparator()->compareTables($before, $after));
    }

    public function plan(Connection $connection): array
    {
        $plan = (new NullableReferences(OptionalReferences::KANBAN_OWNERS, 'Kanban owner'))->plan($connection);
        array_push($plan['sql'], ...$this->uniquenessPlan($connection));
        return $plan;
    }

    public function apply(Connection $connection): array
    {
        $plan = $this->plan($connection); // Audit owners and duplicate states before changing either.
        if ($plan['sql'] && $connection->isTransactionActive() && !$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new \RuntimeException('MySQL Kanban DDL must run outside an application transaction.');
        }
        $apply = function () use ($connection): array {
            $counts = (new NullableReferences(OptionalReferences::KANBAN_OWNERS, 'Kanban owner'))->apply($connection);
            foreach ($this->uniquenessPlan($connection) as $sql) {
                $connection->executeStatement($sql);
            }
            return $counts;
        };
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? $connection->transactional($apply) : $apply();
    }
}
