<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;

/** Frozen upgrade from a serialized selection to ordered, FK-backed memberships. */
final class NetworkPortAggregateOrigins
{
    public const TABLE = 'glpi_networkportaggregateorigins';

    public static function configureSchema(Schema $schema): void
    {
        $schema->getTable('glpi_networkportaggregates')->dropColumn('networkports_id_list');
        $schema->createTable(self::TABLE);
        self::configureTable($schema->getTable(self::TABLE));
    }

    private static function configureTable(Table $table): void
    {
        $table->addColumn('id', 'bigint', ['autoincrement' => true]);
        $table->addColumn('networkportaggregates_id', 'bigint');
        $table->addColumn('networkports_id', 'bigint');
        $table->addColumn('position', 'integer');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['networkportaggregates_id', 'networkports_id'], 'aggregate_origin');
        $table->addUniqueIndex(['networkportaggregates_id', 'position'], 'aggregate_position');
    }

    private static function decode(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        $values = json_decode($value, true);
        if (!is_array($values)) {
            $values = [];
            foreach (explode(' ', trim($value)) as $pair) {
                if (!str_contains($pair, '=>')) {
                    throw new \RuntimeException('Malformed legacy aggregate origin list');
                }
                [$key, $id] = explode('=>', $pair, 2);
                if ($key === '') {
                    throw new \RuntimeException('Malformed legacy aggregate origin key');
                }
                $values[] = urldecode($id);
            }
        }
        $ids = [];
        foreach ($values as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int)$id <= 0) {
                throw new \RuntimeException('Invalid legacy aggregate origin ID');
            }
            $ids[(int)$id] = (int)$id;
        }
        return array_values($ids);
    }

    public function plan(Connection $connection): array
    {
        $manager = $connection->createSchemaManager();
        $legacy = $manager->introspectTable('glpi_networkportaggregates');
        $hasList = $legacy->hasColumn('networkports_id_list');
        $exists = $manager->tablesExist([self::TABLE]);
        if (!$hasList && !$exists) {
            throw new \RuntimeException('Aggregate origin storage is missing');
        }
        $rows = [];
        if ($hasList) {
            foreach ($connection->fetchAllAssociative('SELECT id, networkports_id_list FROM glpi_networkportaggregates ORDER BY id') as $row) {
                $ids = self::decode($row['networkports_id_list']);
                foreach ($ids as $id) {
                    if (!$connection->fetchOne('SELECT id FROM glpi_networkports WHERE id = ?', [$id])) {
                        throw new \RuntimeException('Orphaned legacy aggregate origin: ' . $id);
                    }
                }
                if ($exists) {
                    $actual = array_map('intval', $connection->fetchFirstColumn('SELECT networkports_id FROM ' . self::TABLE . ' WHERE networkportaggregates_id = ? ORDER BY position, id', [$row['id']]));
                    if ($actual && $actual !== $ids) {
                        throw new \RuntimeException('Canonical and legacy aggregate origins disagree');
                    }
                }
                $rows[(int)$row['id']] = $ids;
            }
        }
        $table = new Table(self::TABLE);
        self::configureTable($table);
        // Frozen targets belong to this upgrade, not a runtime relationship catalogue.
        $table->addForeignKeyConstraint('glpi_networkportaggregates', ['networkportaggregates_id'], ['id'], ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'], 'fk_networkportaggregateorigins_networkportaggregates_id');
        $table->addForeignKeyConstraint('glpi_networkports', ['networkports_id'], ['id'], ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'], 'fk_networkportaggregateorigins_networkports_id');
        $constraints = [];
        if ($exists) {
            $actual = $manager->introspectTable(self::TABLE);
            foreach ($table->getForeignKeys() as $key) {
                if (!$actual->hasForeignKey($key->getName())) {
                    $constraints[] = $connection->getDatabasePlatform()->getCreateForeignKeySQL($key, self::TABLE);
                    continue;
                }
                $found = $actual->getForeignKey($key->getName());
                if ($found->getForeignTableName() !== $key->getForeignTableName() || $found->getLocalColumns() !== $key->getLocalColumns()
                    || $found->getForeignColumns() !== ['id'] || !in_array($found->onDelete(), [null, 'RESTRICT', 'NO ACTION'], true)
                    || !in_array($found->onUpdate(), [null, 'RESTRICT', 'NO ACTION'], true)) {
                    throw new \RuntimeException('Aggregate membership table has incompatible foreign keys');
                }
            }
            foreach ($table->getIndexes() as $index) {
                if (!$index->isUnique()) {
                    continue;
                }
                $columns = array_map(static fn (string $column): string => trim($column, '`"'), $index->getColumns());
                $matches = array_filter($actual->getIndexes(), static fn ($found): bool => $found->isUnique()
                    && array_map(static fn (string $column): string => trim($column, '`"'), $found->getColumns()) === $columns);
                if (!$matches) {
                    throw new \RuntimeException('Aggregate membership table lacks its identity/uniqueness constraint');
                }
            }
            if ($connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' o LEFT JOIN glpi_networkports p ON p.id = o.networkports_id LEFT JOIN glpi_networkportaggregates a ON a.id = o.networkportaggregates_id WHERE p.id IS NULL OR a.id IS NULL')) {
                throw new \RuntimeException('Orphaned canonical aggregate origins');
            }
            if ($connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE networkports_id <= 0 OR position < 0')) {
                throw new \RuntimeException('Invalid canonical aggregate origin or position');
            }
        }
        $after = clone $legacy;
        if ($hasList) {
            $after->dropColumn('networkports_id_list');
        }
        return ['create_sql' => $exists ? [] : $connection->getDatabasePlatform()->getCreateTableSQL($table),
            'constraint_sql' => $constraints, 'drop_sql' => $connection->getDatabasePlatform()->getAlterTableSQL($manager->createComparator()->compareTables($legacy, $after)), 'rows' => $rows];
    }

    public function apply(Connection $connection): array
    {
        $plan = $this->plan($connection);
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (($plan['create_sql'] || $plan['constraint_sql'] || $plan['drop_sql']) && !$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL aggregate origin DDL must run outside an application transaction');
        }
        $apply = static function () use ($connection, $plan): array {
            foreach ($plan['create_sql'] as $statement) {
                $connection->executeStatement($statement);
            }
            $connection->transactional(static function () use ($connection, $plan): void {
                foreach ($plan['rows'] as $aggregate => $ids) {
                    if ($connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE networkportaggregates_id = ?', [$aggregate])) {
                        continue;
                    }
                    foreach ($ids as $position => $id) {
                        $connection->insert(self::TABLE, ['networkportaggregates_id' => $aggregate, 'networkports_id' => $id, 'position' => $position]);
                    }
                }
            });
            foreach (array_merge($plan['constraint_sql'], $plan['drop_sql']) as $statement) {
                $connection->executeStatement($statement);
            }
            return $plan;
        };
        return $postgres ? $connection->transactional($apply) : $apply();
    }
}
