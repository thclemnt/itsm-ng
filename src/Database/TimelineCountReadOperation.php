<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Repository\DocumentRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\TimelineRepository;

/** Completed timeline counts on the selected read owner. */
final class TimelineCountReadOperation implements ReadQueryOwner
{
    use PrivateReadOwnership;

    public function __construct(Connection $connection, ?EntityManager $manager = null)
    {
        // Preserve manager creation before virtual table callbacks; private cache
        // authority is resolved only when the first selected table is read.
        $this->initializeReadManager($connection, $manager);
    }

    public function solutions(string $table, TimelineSelection $selection): int
    {
        return $this->count($table, $selection->criteria['solutions']);
    }

    public function followups(string $table, TimelineSelection $selection): int
    {
        return $this->count($table, $selection->criteria['followups']);
    }

    public function tasks(string $table, TimelineSelection $selection): int
    {
        return $this->count($table, $selection->criteria['tasks']);
    }

    public function documents(string $type, int $item, ITILDocumentAccess $access): int
    {
        $this->beginRead();
        $this->initializeReadCaches();
        return (new DocumentRepository($this->manager))->countTimelineDocuments($type, $item, $access);
    }

    public function validations(string $table, array $criteria): int
    {
        $this->beginRead();
        $this->initializeReadCaches();
        $this->metadata($table);
        return (new TimelineRepository($this->manager))->countValidations($table, $criteria);
    }

    private function count(string $table, array $criteria): int
    {
        $this->beginRead();
        $this->initializeReadCaches();
        $this->metadata($table);
        return (new RecordRepository($this->manager))->countMatching($table, $criteria, true, $this);
    }
}
