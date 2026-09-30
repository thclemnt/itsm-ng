<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\OptionalReferences;

final class TreeParentReferences
{
    public const VERSION = '20260929_nullable_tree_parent_references';

    public function plan(Connection $connection): array
    {
        $plan = (new NullableReferences(OptionalReferences::TREE_PARENTS, 'tree parent'))->plan($connection);
        TreeParentAudit::assertAcyclic($connection, OptionalReferences::TREE_PARENTS);
        array_push($plan['sql'], ...(new TreeUniqueness())->plan($connection));
        return $plan;
    }

    public function apply(Connection $connection): array
    {
        $this->plan($connection); // Audit all references, cycles and uniqueness before DDL.
        $apply = static function () use ($connection): array {
            $counts = (new NullableReferences(OptionalReferences::TREE_PARENTS, 'tree parent'))->apply($connection);
            foreach ((new TreeUniqueness())->plan($connection) as $sql) {
                $connection->executeStatement($sql);
            }
            return $counts;
        };
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? $connection->transactional($apply) : $apply();
    }
}
