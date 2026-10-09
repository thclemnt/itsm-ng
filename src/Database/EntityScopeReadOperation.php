<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use DbUtils;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;

use function getEntitiesRestrictCriteria;

/** One dropdown owns mapping state; every restriction still reads current tree rows. */
final class EntityScopeReadOperation
{
    private ?TreeReadOperation $reader = null;
    private ?Connection $connection = null;
    private ?DBAdapter $database = null;

    public function criteria($table = '', $field = '', $value = '', $recursive = false, $complete = false): array
    {
        return getEntitiesRestrictCriteria($table, $field, $value, $recursive, $complete, $this);
    }

    public function restriction($table = '', $field = '', $value = '', $recursive = false, $complete = false): EntityRestriction
    {
        return (new DbUtils($this))->getEntityRestriction($table, $field, $value, $recursive, $complete);
    }

    public function rows(DBAdapter $database, string $table, array $fields, array $criteria): array
    {
        // Permission construction may follow a virtual model/display callback.
        // Re-resolve its selected route before every query; retain no tree rows.
        $connection = $database->getDoctrineConnection();
        if ($this->reader === null || $this->database !== $database
            || $this->connection !== $connection) {
            $this->reader?->close();
            $this->reader = null;
            $this->connection = $connection;
            $this->database = $database;
        }
        $rows = TreeReadOperation::projectedRows($connection, $table, $fields, $criteria);
        if ($rows !== null) {
            return $rows;
        }
        return Orm::withReadConnection($connection, function (?EntityManager $manager) use ($connection, $table, $fields, $criteria): array {
            $reader = $manager === null
                ? ($this->reader ??= new TreeReadOperation($connection))
                : new TreeReadOperation($connection, $manager);
            return $reader->rows($table, $fields, $criteria);
        });
    }

    public function __destruct()
    {
        $this->reader?->close();
    }
}
