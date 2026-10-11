<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Toolbox;
use User;

/** Current scalar authors; custom readers retain metadata for one timeline render. */
final class TimelineAuthorReader
{
    private ?RecordReadOperation $records = null;
    private ?Connection $connection = null;
    private ?DBAdapter $database = null;
    private mixed $cache = null;

    public function load(User $model, $id, DBAdapter $database): bool
    {
        if ($model::class !== User::class) {
            return $model->getTimelineAuthorFromDB($id);
        }
        if ($id === null || strlen($id) == 0) {
            return false;
        }
        // Resolve the current route after each extensible display callback.
        $connection = $database->getDoctrineConnection();
        $cache = $GLOBALS['GLPI_CACHE'] ?? null;
        $row = Orm::withReadConnection($connection, function (?EntityManager $manager) use ($connection, $database, $cache, $id): ?array {
            if ($manager !== null) {
                $this->records?->close();
                $this->records = null;
                $this->connection = $connection;
                $this->database = $database;
                $this->cache = $cache;
                return (new RecordReadOperation($connection, $manager))
                    ->scalarRow('glpi_users', (int)Toolbox::cleanInteger($id));
            }
            if ($this->records === null || $this->database !== $database
                || $this->connection !== $connection || $this->cache !== $cache) {
                $this->records?->close();
                $this->records = new RecordReadOperation($connection);
                $this->connection = $connection;
                $this->database = $database;
                $this->cache = $cache;
            }
            return $this->records->scalarRow('glpi_users', (int)Toolbox::cleanInteger($id));
        });
        if ($row === null) {
            return false;
        }
        $model->fields = $row;
        $model->post_getFromDB();
        return true;
    }

    public function __destruct()
    {
        $this->records?->close();
    }
}
