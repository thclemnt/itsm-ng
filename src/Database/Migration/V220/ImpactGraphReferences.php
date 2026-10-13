<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\BooleanType;
use RuntimeException;

final class ImpactGraphReferences
{
    public const FLAGS = ['glpi_impactitems' => ['is_slave'], 'glpi_impactcontexts' => ['show_depends', 'show_impact']];

    private function references(): NullableReferences
    {
        return new NullableReferences(ReferenceHistory::get('optional', 'IMPACT_GRAPH'), 'impact graph');
    }

    private function booleanSql(Connection $connection): array
    {
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $sql = [];
        foreach (self::FLAGS as $table => $flags) {
            $columns = $connection->createSchemaManager()->listTableColumns($table);
            foreach ($flags as $column) {
                if ($platform instanceof PostgreSQLPlatform && $columns[$column]->getType() instanceof BooleanType) {
                    continue;
                }
                $field = $quote($column);
                if ($connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($table) . ' WHERE ' . $field . ' IS NULL OR ' . $field . ' NOT IN (0, 1)')) {
                    throw new RuntimeException('Invalid impact graph boolean: ' . $table . '.' . $column);
                }
                if ($platform instanceof PostgreSQLPlatform) {
                    $sql[] = 'ALTER TABLE ' . $quote($table) . ' ALTER ' . $field . ' DROP DEFAULT';
                    $sql[] = 'ALTER TABLE ' . $quote($table) . ' ALTER ' . $field . ' TYPE BOOLEAN USING (' . $field . ' = 1)';
                    $sql[] = 'ALTER TABLE ' . $quote($table) . ' ALTER ' . $field . ' SET DEFAULT TRUE';
                }
            }
        }
        return $sql;
    }

    public function plan(Connection $connection): array
    {
        $plan = $this->references()->plan($connection);
        array_push($plan['sql'], ...$this->booleanSql($connection));
        return $plan;
    }

    public function apply(Connection $connection): array
    {
        $this->plan($connection); // Audit all references and flags before changing either.
        $booleanSql = $this->booleanSql($connection);
        $apply = function () use ($connection, $booleanSql): array {
            $counts = $this->references()->apply($connection);
            foreach ($booleanSql as $sql) {
                $connection->executeStatement($sql);
            }
            return $counts;
        };
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? $connection->transactional($apply) : $apply();
    }
}
