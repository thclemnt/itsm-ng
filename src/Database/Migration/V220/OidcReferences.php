<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BooleanType;

/** One refresh state per existing user; audit every change before any DDL. */
final class OidcReferences
{
    public const FLAGS = ['glpi_oidc_users' => ['update' => false], 'glpi_oidc_config' => ['is_activate' => false, 'is_forced' => false, 'sso_link_users' => true]];

    public static function configureTable(Table $table): void
    {
        if (!$table->hasIndex('oidc_users_user')) {
            $table->addUniqueIndex(['user_id'], 'oidc_users_user');
        } elseif (!$table->getIndex('oidc_users_user')->isUnique() || $table->getIndex('oidc_users_user')->getColumns() !== ['user_id']) {
            throw new \RuntimeException('Unexpected OIDC user uniqueness definition');
        }
    }

    public function plan(Connection $connection): array
    {
        foreach (['glpi_oidc_config', 'glpi_oidc_mapping'] as $table) {
            if ($connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE id <> 0')) {
                throw new \RuntimeException('Non-singleton OIDC configuration requires correction: ' . $table);
            }
        }
        if ($connection->fetchOne('SELECT COUNT(*) FROM glpi_oidc_users o LEFT JOIN glpi_users u ON u.id = o.user_id WHERE u.id IS NULL')) {
            throw new \RuntimeException('Orphaned OIDC user states require correction');
        }
        if ($connection->fetchOne('SELECT COUNT(*) FROM (SELECT user_id FROM glpi_oidc_users GROUP BY user_id HAVING COUNT(*) > 1) duplicates')) {
            throw new \RuntimeException('Duplicate OIDC user states require correction');
        }
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $sql = [];
        foreach (self::FLAGS as $table => $flags) {
            $before = $manager->introspectTable($table);
            foreach ($flags as $column => $default) {
                if ($platform instanceof PostgreSQLPlatform && $before->getColumn($column)->getType() instanceof BooleanType) {
                    continue;
                }
                $field = $quote($column);
                if ($connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($table) . ' WHERE ' . $field . ' IS NULL OR ' . $field . ' NOT IN (0, 1)')) {
                    throw new \RuntimeException('Invalid OIDC boolean: ' . $table . '.' . $column);
                }
                if ($platform instanceof PostgreSQLPlatform) {
                    $sql[] = 'ALTER TABLE ' . $quote($table) . ' ALTER ' . $field . ' DROP DEFAULT';
                    $sql[] = 'ALTER TABLE ' . $quote($table) . ' ALTER ' . $field . ' TYPE BOOLEAN USING (' . $field . ' = 1)';
                    $sql[] = 'ALTER TABLE ' . $quote($table) . ' ALTER ' . $field . ' SET DEFAULT ' . ($default ? 'TRUE' : 'FALSE');
                }
            }
            if ($table === 'glpi_oidc_users') {
                $after = clone $before;
                self::configureTable($after);
                array_push($sql, ...$platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)));
            }
        }
        return $sql;
    }

    public function apply(Connection $connection): array
    {
        $sql = $this->plan($connection);
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if ($sql && !$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL OIDC DDL must run outside an application transaction');
        }
        $apply = static function () use ($connection, $sql): array {
            foreach ($sql as $statement) {
                $connection->executeStatement($statement);
            }
            return $sql;
        };
        return $postgres ? $connection->transactional($apply) : $apply();
    }
}
