<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/** Split inheritance policy from selected IDs with writers stopped during upgrade. */
final class EntityConfigurationReferences
{
    public const VERSION = '20260930_entity_configuration_references';

    public static function configureTable(Table $table): void
    {
        foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
            $table->getColumn($column)->setNotnull(false)->setDefault(null);
            if (!$table->hasColumn($definition['mode'])) {
                $table->addColumn($definition['mode'], Types::STRING, ['length' => 16, 'notnull' => true, 'default' => $definition['default']]);
            }
        }
    }

    public static function checkName(string $column): string
    {
        return 'glpi_entities_' . ReferenceHistory::get('inherited', 'FIELDS')[$column]['mode'] . '_selection';
    }

    public static function checkSql(string $column): string
    {
        $definition = ReferenceHistory::get('inherited', 'FIELDS')[$column];
        $mode = $definition['mode'];
        $choices = $definition['empty_zero'] ? "'explicit', 'inherit'" : "'explicit', 'inherit', 'unchanged'";
        $selected = $definition['empty_zero'] ? "($column IS NULL OR $column > 0)" : "($column IS NOT NULL AND $column >= 0)";
        return 'ALTER TABLE glpi_entities ADD CONSTRAINT ' . self::checkName($column)
            . " CHECK ($mode IN ($choices) AND (($mode = 'explicit' AND $selected) OR ($mode <> 'explicit' AND $column IS NULL)))";
    }

    public function plan(Connection $connection): array
    {
        $platform = $connection->getDatabasePlatform();
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable('glpi_entities');
        $schema = $connection->fetchOne($platform instanceof PostgreSQLPlatform ? 'SELECT current_schema()' : 'SELECT DATABASE()');
        $existing = $connection->fetchFirstColumn('SELECT constraint_name FROM information_schema.table_constraints WHERE constraint_schema = ? AND table_name = ? AND constraint_type = ?', [$schema, 'glpi_entities', 'CHECK']);
        $quote = $platform->quoteIdentifier(...);
        $counts = [];
        $checks = [];
        foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
            $field = $quote($column);
            $present = $definition['empty_zero'] ? '> 0' : '>= 0';
            $sentinels = $definition['empty_zero'] ? '-2' : '-2, -10';
            if ($definition['empty_zero'] && $connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($definition['target']) . ' WHERE id = 0')) {
                throw new \RuntimeException('Empty entity setting is a real target identifier: ' . $column);
            }
            $invalid = $connection->fetchOne('SELECT COUNT(*) FROM glpi_entities e LEFT JOIN ' . $quote($definition['target']) . " p ON e.$field = p.id WHERE (e.$field < 0 AND e.$field NOT IN ($sentinels)) OR (e.$field $present AND p.id IS NULL)");
            if ($invalid) {
                throw new \RuntimeException('Invalid entity configuration reference: ' . $column . ' (' . $invalid . ')');
            }
            $checked = in_array(self::checkName($column), $existing, true);
            if ($before->hasColumn($definition['mode'])) {
                $mode = $quote($definition['mode']);
                $choices = $definition['empty_zero'] ? "'explicit', 'inherit'" : "'explicit', 'inherit', 'unchanged'";
                $policy = "$mode IS NULL OR $mode NOT IN ($choices)";
                if (!$definition['empty_zero']) {
                    $policy .= " OR ($mode = 'explicit' AND $field IS NULL)";
                }
                if ($checked) {
                    $policy .= " OR ($mode <> 'explicit' AND $field IS NOT NULL)";
                }
                if ($connection->fetchOne('SELECT COUNT(*) FROM glpi_entities WHERE ' . $policy)) {
                    throw new \RuntimeException('Invalid entity configuration mode: ' . $column);
                }
            }
            $counts[$column] = [
                'inherit' => (int)$connection->fetchOne("SELECT COUNT(*) FROM glpi_entities WHERE $field = -2"),
                'empty' => $definition['empty_zero'] ? (int)$connection->fetchOne("SELECT COUNT(*) FROM glpi_entities WHERE $field = 0") : 0,
                'unchanged' => $definition['empty_zero'] ? 0 : (int)$connection->fetchOne("SELECT COUNT(*) FROM glpi_entities WHERE $field = -10"),
            ];
            if (!$checked) {
                $checks[] = self::checkSql($column);
            }
        }
        // The full audit precedes every DDL statement, including mode columns.
        $after = clone $before;
        self::configureTable($after);
        return ['sql' => $platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)), 'counts' => $counts, 'check_sql' => $checks];
    }

    public function apply(Connection $connection): array
    {
        $plan = $this->plan($connection);
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (($plan['sql'] || $plan['check_sql']) && !$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL entity configuration DDL must run outside an application transaction');
        }
        $apply = static function () use ($connection, $plan): array {
            foreach ($plan['sql'] as $statement) {
                $connection->executeStatement($statement);
            }
            $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
            $connection->transactional(static function () use ($connection, $quote): void {
                foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
                    $field = $quote($column);
                    $mode = $quote($definition['mode']);
                    $connection->executeStatement("UPDATE glpi_entities SET $mode = 'inherit', $field = NULL WHERE $field = -2");
                    if (!$definition['empty_zero']) {
                        $connection->executeStatement("UPDATE glpi_entities SET $mode = 'unchanged', $field = NULL WHERE $field = -10");
                    }
                    $connection->executeStatement("UPDATE glpi_entities SET $mode = 'explicit' WHERE $field IS NOT NULL AND $field >= 0");
                    if ($definition['empty_zero']) {
                        $connection->executeStatement("UPDATE glpi_entities SET $field = NULL WHERE $field = 0");
                    }
                }
            });
            foreach ($plan['check_sql'] as $statement) {
                $connection->executeStatement($statement);
            }
            return $plan;
        };
        return $postgres ? $connection->transactional($apply) : $apply();
    }
}
