<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Repository\UserRepository;

/** One timeline render owns metadata, never author rows or managed entities. */
final class TimelineAuthorReader
{
    private ?EntityManager $manager = null;
    private ?\DBAdapter $database = null;
    private mixed $cache = null;

    public function load(\User $model, $id, \DBAdapter $database): bool
    {
        if ($model::class !== \User::class) {
            return $model->getTimelineAuthorFromDB($id);
        }
        if ($id === null || strlen($id) == 0) {
            return false;
        }
        // Resolve the current route after each extensible display callback.
        $connection = $database->getDoctrineConnection();
        $cache = $GLOBALS['GLPI_CACHE'] ?? null;
        if ($this->manager === null || $this->database !== $database
            || $this->manager->getConnection() !== $connection || $this->cache !== $cache) {
            $this->manager?->clear();
            $this->manager = Orm::create($database);
            $this->database = $database;
            $this->cache = $cache;
        }
        $row = (new UserRepository($this->manager))->timelineAuthor((int)\Toolbox::cleanInteger($id));
        if ($row === null) {
            return false;
        }
        $model->fields = $row;
        $model->post_getFromDB();
        return true;
    }

    public function __destruct()
    {
        $this->manager?->clear();
    }
}
