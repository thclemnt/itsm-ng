<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\ForeignKeys;

/** Frozen hierarchy upgrade: root has no parent; every other parent is a real ID. */
final class EntityParents
{
    public const CHECK = 'glpi_entities_parent_root';

    public static function configureTable(Table $table): void
    {
        $table->getColumn('entities_id')->setNotnull(false)->setDefault(0);
    }

    public static function checkSql(): string
    {
        return 'ALTER TABLE glpi_entities ADD CONSTRAINT ' . self::CHECK
            . ' CHECK ((id = 0 AND entities_id IS NULL) OR (id > 0 AND entities_id IS NOT NULL AND entities_id >= 0 AND entities_id <> id))';
    }

    public function plan(Connection $connection): array
    {
        $parents = $connection->fetchAllKeyValue('SELECT id, entities_id FROM glpi_entities');
        if (!array_key_exists(0, $parents) || ($parents[0] !== null && !in_array((int)$parents[0], [-1, 0], true))) {
            throw new \RuntimeException('Entity hierarchy requires the real root with no selected parent');
        }
        $normalize = $parents[0] === null ? 0 : 1;
        $parents[0] = null;
        foreach ($parents as $id => $parent) {
            if ((int)$id !== 0 && ($parent === null || (int)$parent < 0 || !array_key_exists((int)$parent, $parents))) {
                throw new \RuntimeException('Invalid entity parent at ' . $id . ': ' . var_export($parent, true));
            }
        }
        $finished = [];
        foreach ($parents as $id => $_) {
            $path = [];
            while ($id !== null && !isset($finished[$id])) {
                if (isset($path[$id])) {
                    throw new \RuntimeException('Cyclic entity parents at ' . $id);
                }
                $path[$id] = true;
                $id = $parents[$id] === null ? null : (int)$parents[$id];
            }
            $finished += $path;
        }
        $platform = $connection->getDatabasePlatform();
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable('glpi_entities');
        $after = clone $before;
        self::configureTable($after);
        $sql = $platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after));
        $schema = $connection->fetchOne($platform instanceof PostgreSQLPlatform ? 'SELECT current_schema()' : 'SELECT DATABASE()');
        $exists = $connection->fetchOne('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ?', [$schema, 'glpi_entities', self::CHECK, 'CHECK']);
        $constraints = $exists ? [] : [self::checkSql()];
        $name = ForeignKeys::name('glpi_entities', 'entities_id');
        if (!$before->hasForeignKey($name)) {
            $constraints[] = $platform->getCreateForeignKeySQL(new ForeignKeyConstraint(['entities_id'], 'glpi_entities', ['id'], $name, ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT']), 'glpi_entities');
        } else {
            $foreign = $before->getForeignKey($name);
            if ($foreign->getLocalColumns() !== ['entities_id'] || $foreign->getForeignTableName() !== 'glpi_entities' || $foreign->getForeignColumns() !== ['id']
                || !in_array($foreign->onDelete(), [null, 'RESTRICT', 'NO ACTION'], true) || !in_array($foreign->onUpdate(), [null, 'RESTRICT', 'NO ACTION'], true)) {
                throw new \RuntimeException('Existing entity-parent foreign key has a different definition');
            }
        }
        return ['sql' => $sql, 'constraint_sql' => $constraints, 'root_rows' => $normalize];
    }

    public function apply(Connection $connection): array
    {
        $plan = $this->plan($connection);
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (($plan['sql'] || $plan['constraint_sql']) && !$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL entity-parent DDL must run outside an application transaction');
        }
        $apply = static function () use ($connection, $plan): array {
            foreach ($plan['sql'] as $statement) {
                $connection->executeStatement($statement);
            }
            $connection->executeStatement('UPDATE glpi_entities SET entities_id = NULL WHERE id = 0 AND entities_id IS NOT NULL');
            foreach ($plan['constraint_sql'] as $statement) {
                $connection->executeStatement($statement);
            }
            return $plan;
        };
        return $postgres ? $connection->transactional($apply) : $apply();
    }
}
