<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use RuntimeException;

/** Frozen cleanup: core never implemented a project-template target or consumer. */
final class UnusedProjectTemplateReference
{
    public static function configureTable(Table $table): void
    {
        if (!$table->hasColumn('projecttemplates_id')) {
            return;
        }
        foreach ($table->getForeignKeys() as $foreignKey) {
            if (in_array('projecttemplates_id', $foreignKey->getLocalColumns(), true)) {
                throw new RuntimeException('Custom project-template foreign key requires an explicit migration');
            }
        }
        foreach ($table->getIndexes() as $index) {
            $columns = array_map(static fn ($column) => trim($column, '`"'), $index->getColumns());
            if (in_array('projecttemplates_id', $columns, true)) {
                if (count($columns) !== 1 || $index->isUnique()) {
                    throw new RuntimeException('Custom project-template index requires an explicit migration');
                }
                $table->dropIndex($index->getName());
            }
        }
        $table->dropColumn('projecttemplates_id');
    }

    public function plan(Connection $connection): array
    {
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable('glpi_projects');
        if (!$before->hasColumn('projecttemplates_id')) {
            return ['sql' => []];
        }
        if ($connection->fetchOne('SELECT COUNT(*) FROM glpi_projects WHERE projecttemplates_id IS NOT NULL AND projecttemplates_id <> 0')) {
            throw new RuntimeException('Populated project-template selections require an explicit migration');
        }
        // Plugins may have turned this unused core field into a referenced key.
        // Audit incoming constraints too, before any MySQL DDL can commit.
        foreach ($manager->listTables() as $table) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                if ($foreignKey->getForeignTableName() === 'glpi_projects'
                    && in_array('projecttemplates_id', $foreignKey->getForeignColumns(), true)) {
                    throw new RuntimeException('Incoming project-template foreign key requires an explicit migration');
                }
            }
        }
        $after = clone $before;
        self::configureTable($after);
        return ['sql' => $connection->getDatabasePlatform()->getAlterTableSQL($manager->createComparator()->compareTables($before, $after))];
    }

    public function apply(Connection $connection): array
    {
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $apply = function () use ($connection, $postgres): array {
            $plan = $this->plan($connection);
            if ($plan['sql'] && !$postgres && $connection->isTransactionActive()) {
                throw new RuntimeException('MySQL project-template DDL must run outside an application transaction');
            }
            foreach ($plan['sql'] as $sql) {
                $connection->executeStatement($sql);
            }
            return $plan;
        };
        return $postgres ? $connection->transactional($apply) : $apply();
    }
}
