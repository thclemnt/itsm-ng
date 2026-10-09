<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use RuntimeException;

/** NULL means shared board state; its identity must remain unique after migration. */
final class KanbanOwnership
{
    public static function indexName(AbstractPlatform $platform): string
    {
        return $platform instanceof PostgreSQLPlatform ? 'glpi_items_kanbans_unicity' : 'unicity';
    }

    public static function addToTable(Table $table, AbstractPlatform $platform): void
    {
        SharedOwnerUniqueness::addToTable($table, $platform);
    }

    private function uniquenessPlan(Connection $connection): array
    {
        return (new SharedOwnerUniqueness())->plan($connection, 'glpi_items_kanbans');
    }

    public function plan(Connection $connection): array
    {
        $plan = (new NullableReferences(ReferenceHistory::get('optional', 'KANBAN_OWNERS'), 'Kanban owner'))->plan($connection);
        array_push($plan['sql'], ...$this->uniquenessPlan($connection));
        return $plan;
    }

    public function apply(Connection $connection): array
    {
        $plan = $this->plan($connection); // Audit owners and duplicate states before changing either.
        if ($plan['sql'] && $connection->isTransactionActive() && !$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new RuntimeException('MySQL Kanban DDL must run outside an application transaction.');
        }
        $apply = function () use ($connection): array {
            $counts = (new NullableReferences(ReferenceHistory::get('optional', 'KANBAN_OWNERS'), 'Kanban owner'))->apply($connection);
            foreach ($this->uniquenessPlan($connection) as $sql) {
                $connection->executeStatement($sql);
            }
            return $counts;
        };
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? $connection->transactional($apply) : $apply();
    }
}
