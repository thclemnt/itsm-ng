<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Toolbox;
use User;

/** One timeline render owns metadata, never author rows or managed entities. */
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
        if ($this->records === null || $this->database !== $database
            || $this->connection !== $connection || $this->cache !== $cache) {
            $this->records?->close();
            $this->records = new RecordReadOperation($connection);
            $this->connection = $connection;
            $this->database = $database;
            $this->cache = $cache;
        }
        $row = $this->records->scalarRow('glpi_users', (int)Toolbox::cleanInteger($id));
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
