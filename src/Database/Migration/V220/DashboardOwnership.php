<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use RuntimeException;

final class DashboardOwnership
{
    public const KEYS = ['profile_key' => 'profileId', 'user_key' => 'userId'];

    public static function configureTable(Table $table, AbstractPlatform $platform): void
    {
        $primary = array_map(static fn ($column) => trim($column, '`"'), $table->getPrimaryKey()->getColumns());
        if ($primary !== ['id']) {
            if ($primary !== ['profileId', 'userId']) {
                throw new RuntimeException('Unexpected dashboard primary key');
            }
            $table->dropPrimaryKey();
            $table->setPrimaryKey(['id']);
        }
        $table->getColumn('id')->setAutoincrement(true);
        foreach (self::KEYS as $key => $column) {
            $table->getColumn($column)->setNotnull(false)->setDefault(null);
            if (!$table->hasColumn($key)) {
                $table->addColumn($key, 'bigint', ['notnull' => false, 'columnDefinition' => 'BIGINT GENERATED ALWAYS AS (COALESCE(' . $platform->quoteIdentifier($column) . ', 0)) STORED']);
            }
        }
        if (!$table->hasIndex('dashboard_owners')) {
            $table->addUniqueIndex(array_keys(self::KEYS), 'dashboard_owners');
        } elseif (!$table->getIndex('dashboard_owners')->isUnique() || $table->getIndex('dashboard_owners')->getColumns() !== array_keys(self::KEYS)) {
            throw new RuntimeException('Unexpected dashboard owner uniqueness definition');
        }
    }

    public function plan(Connection $connection): array
    {
        $references = (new NullableReferences(ReferenceHistory::get('optional', 'DASHBOARD_OWNERS'), 'dashboard owner'))->plan($connection);
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $keys = implode(', ', array_map(static fn ($column) => 'COALESCE(' . $quote($column) . ', 0)', self::KEYS));
        if ($connection->fetchOne('SELECT COUNT(*) FROM (SELECT ' . $keys . ' FROM glpi_dashboards GROUP BY ' . $keys . ' HAVING COUNT(*) > 1) duplicates')) {
            throw new RuntimeException('Duplicate normalized dashboard owners');
        }
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable('glpi_dashboards');
        $after = clone $before;
        self::configureTable($after, $platform);
        $sql = $platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after));
        // DBAL temporarily removes MySQL AUTO_INCREMENT while replacing a PK.
        // Restore it after the new numeric primary key has been installed.
        if (!$platform instanceof PostgreSQLPlatform && $before->getPrimaryKey()->getColumns() !== ['id']) {
            $withoutIdentity = clone $after;
            $withoutIdentity->getColumn('id')->setAutoincrement(false);
            array_push($sql, ...$platform->getAlterTableSQL($manager->createComparator()->compareTables($withoutIdentity, $after)));
        }
        return ['sql' => $sql, 'counts' => $references['counts']];
    }

    public function apply(Connection $connection): array
    {
        $plan = $this->plan($connection);
        if ($plan['sql'] && $connection->isTransactionActive() && !$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new RuntimeException('MySQL dashboard DDL must run outside an application transaction.');
        }
        $apply = static function () use ($connection, $plan): array {
            foreach ($plan['sql'] as $sql) {
                $connection->executeStatement($sql);
            }
            return (new NullableReferences(ReferenceHistory::get('optional', 'DASHBOARD_OWNERS'), 'dashboard owner'))->apply($connection);
        };
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? $connection->transactional($apply) : $apply();
    }
}
