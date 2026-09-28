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
        foreach (OptionalReferences::TREE_PARENTS as $table => $relations) {
            $parent = array_key_first($relations);
            $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
            $parents = $connection->fetchAllKeyValue('SELECT id, ' . $quote($parent) . ' FROM ' . $quote($table));
            $finished = [];
            foreach ($parents as $id => $_) {
                $path = [];
                while ($id && !isset($finished[$id])) {
                    if (isset($path[$id])) {
                        throw new \RuntimeException('Cyclic tree parents: ' . $table . ' at ' . $id);
                    }
                    $path[$id] = true;
                    $id = (int)($parents[$id] ?? 0);
                }
                $finished += $path;
            }
        }
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
