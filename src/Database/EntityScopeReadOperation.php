<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Repository\TreeRepository;

/** One dropdown owns mapping state; every restriction still reads current tree rows. */
final class EntityScopeReadOperation
{
    private ?EntityManager $manager = null;
    private ?\DBAdapter $database = null;

    public function criteria($table = '', $field = '', $value = '', $recursive = false, $complete = false): array
    {
        return \getEntitiesRestrictCriteria($table, $field, $value, $recursive, $complete, $this);
    }

    public function rows(\DBAdapter $database, string $table, array $fields, array $criteria): array
    {
        // Permission construction may follow a virtual model/display callback.
        // Re-resolve its selected route before every query; retain no tree rows.
        $connection = $database->getDoctrineConnection();
        if ($this->manager === null || $this->database !== $database
            || $this->manager->getConnection() !== $connection) {
            $this->manager?->clear();
            $this->manager = Orm::forConnection($connection);
            $this->database = $database;
        }
        return (new TreeRepository($this->manager))->rows($table, $fields, $criteria);
    }

    public function __destruct()
    {
        $this->manager?->clear();
    }
}
