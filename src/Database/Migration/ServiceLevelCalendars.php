<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/** SLM owns calendar policy; SLA/OLA have inherited it since the SLM split. */
final class ServiceLevelCalendars
{
    public const VERSION = '20260929_service_level_calendar_policy';
    public const CHECK = 'glpi_slms_calendar_selection';

    public static function configureTable(Table $table): void
    {
        if ($table->getName() === 'glpi_slms') {
            $table->getColumn('calendars_id')->setNotnull(false)->setDefault(null);
            if (!$table->hasColumn('use_ticket_calendar')) {
                $table->addColumn('use_ticket_calendar', Types::BOOLEAN, ['default' => false, 'notnull' => true]);
            }
        } elseif ($table->hasColumn('calendars_id')) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                if (in_array('calendars_id', $foreignKey->getLocalColumns(), true)) {
                    $table->removeForeignKey($foreignKey->getName());
                }
            }
            foreach ($table->getIndexes() as $index) {
                if (in_array('calendars_id', array_map(static fn ($column) => trim($column, '`'), $index->getColumns()), true)) {
                    $table->dropIndex($index->getName());
                }
            }
            $table->dropColumn('calendars_id');
        }
    }

    public static function checkSql(): string
    {
        return 'ALTER TABLE glpi_slms ADD CONSTRAINT ' . self::CHECK
            . ' CHECK (calendars_id IS NULL OR (calendars_id > 0 AND NOT use_ticket_calendar))';
    }

    public function plan(Connection $connection): array
    {
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $before = $manager->introspectTable('glpi_slms');
        if ($connection->fetchOne('SELECT COUNT(*) FROM glpi_calendars WHERE id = 0')) {
            throw new \RuntimeException('Zero is a real calendar identifier');
        }
        $invalid = $connection->fetchOne('SELECT COUNT(*) FROM glpi_slms s LEFT JOIN glpi_calendars c ON c.id = s.calendars_id WHERE s.calendars_id < -1 OR (s.calendars_id > 0 AND c.id IS NULL)');
        if ($invalid) {
            throw new \RuntimeException('Invalid service-level calendar references: ' . $invalid);
        }
        if ($before->hasColumn('use_ticket_calendar') && $connection->fetchOne('SELECT COUNT(*) FROM glpi_slms WHERE use_ticket_calendar = ? AND calendars_id > 0', [true], [Types::BOOLEAN])) {
            throw new \RuntimeException('Conflicting service-level calendar policies');
        }
        // No agreement's effective calendar is lost: all must have an existing SLM.
        foreach (['glpi_slas', 'glpi_olas'] as $table) {
            if ($connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' a LEFT JOIN glpi_slms s ON s.id = a.slms_id WHERE s.id IS NULL')) {
                throw new \RuntimeException('Agreement without service-level parent: ' . $table);
            }
        }
        $sql = [];
        foreach (['glpi_slms', 'glpi_slas', 'glpi_olas'] as $table) {
            $before = $manager->introspectTable($table);
            $after = clone $before;
            self::configureTable($after);
            array_push($sql, ...$platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)));
        }
        $schema = $connection->fetchOne($platform instanceof PostgreSQLPlatform ? 'SELECT current_schema()' : 'SELECT DATABASE()');
        $checked = $connection->fetchOne('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ?', [$schema, 'glpi_slms', self::CHECK, 'CHECK']);
        return [
            'sql' => $sql,
            'ticket_calendars' => (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_slms WHERE calendars_id = -1'),
            'always_open' => (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_slms WHERE calendars_id = 0'),
            'check_sql' => $checked ? [] : [self::checkSql()],
        ];
    }

    public function apply(Connection $connection): array
    {
        $plan = $this->plan($connection);
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (($plan['sql'] || $plan['check_sql']) && !$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL service-level calendar DDL must run outside an application transaction.');
        }
        $apply = static function () use ($connection, $plan): array {
            foreach ($plan['sql'] as $sql) {
                $connection->executeStatement($sql);
            }
            $connection->transactional(static function () use ($connection): void {
                $connection->executeStatement('UPDATE glpi_slms SET use_ticket_calendar = ?, calendars_id = NULL WHERE calendars_id = -1', [true], [Types::BOOLEAN]);
                $connection->executeStatement('UPDATE glpi_slms SET calendars_id = NULL WHERE calendars_id = 0');
            });
            foreach ($plan['check_sql'] as $sql) {
                $connection->executeStatement($sql);
            }
            return $plan;
        };
        return $postgres ? $connection->transactional($apply) : $apply();
    }
}
