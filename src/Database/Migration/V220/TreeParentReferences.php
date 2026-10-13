<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

final class TreeParentReferences
{
    public function plan(Connection $connection): array
    {
        $plan = (new NullableReferences(ReferenceHistory::get('optional', 'TREE_PARENTS'), 'tree parent'))->plan($connection);
        TreeParentAudit::assertAcyclic($connection, ReferenceHistory::get('optional', 'TREE_PARENTS'));
        array_push($plan['sql'], ...(new TreeUniqueness())->plan($connection));
        return $plan;
    }

    public function apply(Connection $connection): array
    {
        $this->plan($connection); // Audit all references, cycles and uniqueness before DDL.
        $apply = static function () use ($connection): array {
            $counts = (new NullableReferences(ReferenceHistory::get('optional', 'TREE_PARENTS'), 'tree parent'))->apply($connection);
            foreach ((new TreeUniqueness())->plan($connection) as $sql) {
                $connection->executeStatement($sql);
            }
            return $counts;
        };
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? $connection->transactional($apply) : $apply();
    }
}
