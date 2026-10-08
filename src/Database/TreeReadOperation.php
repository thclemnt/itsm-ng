<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use itsmng\Database\Repository\TreeRepository;
use ReflectionMethod;

/** One private tree reader; no rows or mutable manager leave its lifetime. */
final class TreeReadOperation implements ReadQueryOwner
{
    use PrivateReadOwnership;

    public function rows(string $table, array $fields, array $criteria, array|string $order = []): array
    {
        if ($this->ownedMapping && $order === []
            && (new ReflectionMethod($this->connection, 'getDatabasePlatform'))->getDeclaringClass()->getName() === Connection::class
            && ($mapping = EntityRegistry::treePointMapping($table)) !== null) {
            $rows = TreeRepository::projectedPointRows($this->connection, $table, $mapping, $fields, $criteria);
            if ($rows !== null) {
                return $rows;
            }
        }
        $metadata = $this->metadata($table);
        $repository = new TreeRepository($this->manager);
        if ($this->ownedMapping && $this->defaultIdentifiers($metadata) !== null && $order === []) {
            $rows = $repository->pointRows($table, $fields, $criteria);
            if ($rows !== null) {
                return $rows;
            }
        }
        return $repository->rows($table, $fields, $criteria, $order, $this);
    }
}
