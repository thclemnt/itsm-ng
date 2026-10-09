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

    /** Bounded core point reads need no manager; null retains the ordinary reader. */
    public static function projectedRows(Connection $connection, string $table, array $fields, array $criteria): ?array
    {
        if ((new ReflectionMethod($connection, 'getDatabasePlatform'))->getDeclaringClass()->getName() !== Connection::class
            || !Orm::ownsReadMapping($connection)
            || ($mapping = EntityRegistry::treePointMapping($table)) === null) {
            return null;
        }
        Orm::registerTypes();
        return TreeRepository::projectedPointRows($connection, $table, $mapping, $fields, $criteria);
    }

    public function rows(string $table, array $fields, array $criteria, array|string $order = []): array
    {
        if ($this->ownedMapping && $order === []) {
            $rows = self::projectedRows($this->connection, $table, $fields, $criteria);
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
