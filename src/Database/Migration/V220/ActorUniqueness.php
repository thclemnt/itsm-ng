<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use RuntimeException;

/** One named actor, or one anonymous email, per parent and role. */
final class ActorUniqueness
{
    public const TABLES = [
        'glpi_tickets_users' => ['tickets_id', 'users_id'],
        'glpi_problems_users' => ['problems_id', 'users_id'],
        'glpi_changes_users' => ['changes_id', 'users_id'],
        'glpi_suppliers_tickets' => ['tickets_id', 'suppliers_id'],
        'glpi_problems_suppliers' => ['problems_id', 'suppliers_id'],
        'glpi_changes_suppliers' => ['changes_id', 'suppliers_id'],
    ];

    public static function indexName(string $table, AbstractPlatform $platform): string
    {
        return $platform instanceof PostgreSQLPlatform ? $table . '_unicity' : 'unicity';
    }

    public static function emailExpression(string $actor): string
    {
        return "CASE WHEN COALESCE($actor, 0) = 0 THEN COALESCE(alternative_email, '') ELSE '' END";
    }

    public static function addToTable(Table $table, AbstractPlatform $platform): void
    {
        $name = $table->getName();
        [$parent, $actor] = self::TABLES[$name];
        if (!$table->hasIndex($name . '_actor_parent')) {
            $table->addIndex([$parent], $name . '_actor_parent');
        }
        if (!$table->hasColumn('actor_key')) {
            $table->addColumn('actor_key', 'bigint', ['notnull' => false, 'columnDefinition' => 'BIGINT GENERATED ALWAYS AS (COALESCE(' . $actor . ', 0)) STORED']);
        }
        if (!$table->hasColumn('actor_email_key')) {
            $table->addColumn('actor_email_key', 'string', ['length' => 255, 'notnull' => false, 'columnDefinition' => 'VARCHAR(255) GENERATED ALWAYS AS (' . self::emailExpression($actor) . ') STORED']);
        }
        $columns = [$parent, 'type', 'actor_key', 'actor_email_key'];
        $indexName = self::indexName($name, $platform);
        if ($table->hasIndex($indexName)) {
            $index = $table->getIndex($indexName);
            $existing = array_map(static fn ($column) => trim($column, '`'), $index->getColumns());
            if ($index->isUnique() && $existing === $columns) {
                return;
            }
            $legacy = [$parent, 'type', $actor];
            if ($actor === 'users_id') {
                $legacy[] = 'alternative_email';
            }
            if (!$index->isUnique() || $existing !== $legacy) {
                throw new RuntimeException('Unexpected actor uniqueness definition: ' . $name);
            }
            $table->dropIndex($indexName);
        }
        $table->addUniqueIndex($columns, $indexName);
    }

    public function plan(Connection $connection): array
    {
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $sql = [];
        foreach (self::TABLES as $table => [$parent, $actor]) {
            $duplicates = $connection->fetchOne('SELECT COUNT(*) FROM (SELECT 1 FROM ' . $table . ' GROUP BY ' . $parent . ', type, COALESCE(' . $actor . ', 0), ' . self::emailExpression($actor) . ' HAVING COUNT(*) > 1) duplicates');
            if ($duplicates) {
                throw new RuntimeException('Duplicate ITIL actors: ' . $table);
            }
            $before = $manager->introspectTable($table);
            $after = clone $before;
            self::addToTable($after, $platform);
            $supporting = $table . '_actor_parent';
            if (!$before->hasIndex($supporting)) {
                $sql[] = $platform->getCreateIndexSQL($after->getIndex($supporting), $table);
                $before->addIndex([$parent], $supporting);
            }
            array_push($sql, ...$platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)));
        }
        return $sql;
    }
}
