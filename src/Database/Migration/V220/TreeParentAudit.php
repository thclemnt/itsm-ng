<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use RuntimeException;

/** Audit complete parent chains before changing a tree's schema. */
final class TreeParentAudit
{
    public static function assertAcyclic(Connection $connection, array $relations): void
    {
        $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
        foreach ($relations as $table => $columns) {
            $parent = array_key_first($columns);
            $parents = $connection->fetchAllKeyValue('SELECT id, ' . $quote($parent) . ' FROM ' . $quote($table));
            $finished = [];
            foreach ($parents as $id => $_) {
                $path = [];
                while ($id && !isset($finished[$id])) {
                    if (isset($path[$id])) {
                        throw new RuntimeException('Cyclic tree parents: ' . $table . ' at ' . $id);
                    }
                    $path[$id] = true;
                    $id = (int)($parents[$id] ?? 0);
                }
                $finished += $path;
            }
        }
    }
}
