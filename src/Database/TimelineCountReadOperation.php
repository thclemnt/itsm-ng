<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\ClassMetadata;
use itsmng\Database\Entity;
use itsmng\Database\Entity\ITILFollowup;
use itsmng\Database\Entity\ITILSolution;
use itsmng\Database\Repository\DocumentRepository;
use itsmng\Database\Repository\ITILTaskRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\TimelineRepository;

/** One selected connection owns canonical scalar metadata and local extension fallbacks. */
final class TimelineCountReadOperation
{
    use PrivateReadOwnership;

    public function __construct(Connection $connection)
    {
        // Preserve manager creation before virtual table callbacks; private cache
        // authority is resolved only when the first selected table is read.
        $this->initializeReadManager($connection);
    }

    public function solutions(string $table, TimelineSelection $selection): int
    {
        $criteria = $selection->criteria['solutions'];
        $metadata = $this->admitted($table, ITILSolution::class);
        if ($metadata === null || !in_array($criteria['itemtype'], ['Ticket', 'Change', 'Problem'], true) || !$this->plain($criteria['items_id'])) {
            return $this->countLocally($table, $criteria);
        }
        return (new TimelineRepository($this->manager))->nativeSubjectCount($metadata, $criteria['itemtype'], $criteria['items_id']);
    }

    public function followups(string $table, TimelineSelection $selection): int
    {
        $criteria = $selection->criteria['followups'];
        $metadata = $this->admitted($table, ITILFollowup::class);
        if ($metadata === null || !in_array($criteria['itemtype'], ['Ticket', 'Change', 'Problem'], true) || !$this->plain($criteria['items_id']) || !$this->plain($selection->followupAuthor)) {
            return $this->countLocally($table, $criteria);
        }
        return (new TimelineRepository($this->manager))->nativeSubjectCount(
            $metadata,
            $criteria['itemtype'],
            $criteria['items_id'],
            $selection->followupRestricted,
            $selection->followupAuthor
        );
    }

    public function tasks(string $table, TimelineSelection $selection): int
    {
        $criteria = $selection->criteria['tasks'];
        $class = EntityRegistry::tables()[$table] ?? null;
        $metadata = $class === null ? null : $this->admitted($table, $class);
        $column = array_key_first($criteria);
        $association = null;
        $definition = $metadata === null ? null : (new ITILTaskRepository($this->manager))->definitionForMetadata($metadata);
        if ($definition !== null) {
            [, , $parent] = $definition;
            $mapping = $metadata->associationMappings[$parent];
            if (count($mapping->joinColumns) === 1 && $mapping->joinColumns[0]->name === $column) {
                $association = $parent;
            }
        }
        if ($association === null || !$this->plain($criteria[$column]) || !$this->plain($selection->taskAuthor)) {
            return $this->countLocally($table, $criteria);
        }
        return (new TimelineRepository($this->manager))->nativeTaskCount(
            $metadata,
            $association,
            $criteria[$column],
            $selection->taskRestricted,
            $selection->taskAuthor
        );
    }

    public function documents(string $type, int $item, ITILDocumentAccess $access): int
    {
        if ($this->documentMetadata($type, $access)) {
            return (new DocumentRepository($this->manager))->nativeTimelineDocumentCount($type, $item, $access);
        }
        $manager = $this->fallbackManager();
        try {
            return (new DocumentRepository($manager))->countTimelineDocuments($type, $item, $access);
        } finally {
            if ($manager !== $this->manager) {
                $manager->clear();
            }
        }
    }

    public function validations(string $table, array $criteria): int
    {
        $class = EntityRegistry::tables()[$table] ?? null;
        if ($this->ownedMapping && in_array($class, [Entity\TicketValidation::class, Entity\ChangeValidation::class], true)
            && count($criteria) === 1 && $this->plain(reset($criteria))) {
            $metadata = $this->admitted($table, $class);
            foreach ($metadata?->associationMappings ?? [] as $association) {
                if ($association->isToOneOwningSide() && count($association->joinColumns) === 1
                    && $association->joinColumns[0]->name === array_key_first($criteria)
                    && in_array($association->targetEntity, [Entity\Ticket::class, Entity\Change::class], true)
                    && $this->canonicalMetadata($association->targetEntity) !== null) {
                    return (new TimelineRepository($this->manager))->countValidations($table, $criteria);
                }
            }
        }
        $manager = $this->fallbackManager();
        try {
            return (new TimelineRepository($manager))->countValidations($table, $criteria);
        } finally {
            if ($manager !== $this->manager) {
                $manager->clear();
            }
        }
    }

    private function countLocally(string $table, array $criteria): int
    {
        $manager = $this->fallbackManager();
        try {
            return (new RecordRepository($manager))->countMatching($table, $criteria);
        } finally {
            if ($manager !== $this->manager) {
                $manager->clear();
            }
        }
    }

    /** All IDENTITY targets used by the fixed privacy query stay in core metadata. */
    private function documentMetadata(string $type, ITILDocumentAccess $access): bool
    {
        if (!$this->ownedMapping || !in_array($type, ['Ticket', 'Change', 'Problem'], true)) {
            return false;
        }
        $this->initializeReadCaches();
        // The ordinary repository resolves this task definition even when tasks
        // are hidden; preserve that order and its unsupported-type behavior.
        [$task, , $parent] = (new ITILTaskRepository($this->manager))->definition($type . 'Task');
        $taskMetadata = $this->canonicalMetadata($task);
        $document = $this->canonicalMetadata(Entity\DocumentItem::class);
        if ($taskMetadata === null || $document === null
            || $this->canonicalMetadata($document->associationMappings['documents']->targetEntity) === null) {
            return false;
        }
        if ($access->followups) {
            $followup = $this->canonicalMetadata(ITILFollowup::class);
            $subject = ITILFollowup::subjectAssociation($type);
            if ($followup === null || $this->canonicalMetadata($followup->associationMappings[$subject]->targetEntity) === null
                || (!$access->privateFollowups && $this->canonicalMetadata($followup->associationMappings['author']->targetEntity) === null)) {
                return false;
            }
        }
        if ($access->solutions) {
            $solution = $this->canonicalMetadata(ITILSolution::class);
            $subject = ITILSolution::subjectAssociation($type);
            if ($solution === null || $this->canonicalMetadata($solution->associationMappings[$subject]->targetEntity) === null) {
                return false;
            }
        }
        if ($access->tasks && ($this->canonicalMetadata($taskMetadata->associationMappings[$parent]->targetEntity) === null
            || (!$access->privateTasks && $this->canonicalMetadata($taskMetadata->associationMappings['author']->targetEntity) === null))) {
            return false;
        }
        return true;
    }

    private function canonicalMetadata(string $class): ?ClassMetadata
    {
        $table = array_search($class, EntityRegistry::tables(), true);
        return $table === false || !isset($this->identifiers[$class]) ? null : $this->metadata($table);
    }

    private function admitted(string $table, string $class): ?ClassMetadata
    {
        if (!$this->ownedMapping || (EntityRegistry::tables()[$table] ?? null) !== $class) {
            return null;
        }
        $this->initializeReadCaches();
        $metadata = $this->metadata($table);
        return $metadata->isInheritanceTypeNone() && $metadata->identifier === ['id'] && $metadata->hasField('id') ? $metadata : null;
    }

    private function plain(mixed $value): bool
    {
        return $value === null || is_int($value) || is_bool($value)
            || (is_string($value) && ($value === '' || strtolower($value) === 'null' || preg_match('/^-?[0-9]+$/D', $value)));
    }
}
