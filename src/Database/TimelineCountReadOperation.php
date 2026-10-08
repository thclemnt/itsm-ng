<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;
use itsmng\Database\Entity\ITILFollowup;
use itsmng\Database\Entity\ITILSolution;
use itsmng\Database\Repository\DocumentRepository;
use itsmng\Database\Repository\ITILTaskRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\TimelineRepository;

/** One selected connection and ordinary local manager retain the timeline read order. */
final class TimelineCountReadOperation
{
    private EntityManager $manager;
    private bool $ownedMapping;

    public function __construct(Connection $connection)
    {
        $this->manager = Orm::forConnection($connection);
        $file = (new ReflectionClass($connection->getDatabasePlatform()))->getFileName();
        $package = InstalledVersions::getInstallPath('doctrine/dbal');
        $this->ownedMapping = !method_exists($connection, 'getEventManager') && $file !== false && $package !== null
            && ($file = realpath($file)) !== false && ($package = realpath($package)) !== false
            && str_starts_with($file, $package . '/src/Platforms/');
    }

    public function close(): void
    {
        $this->manager->clear();
    }

    public function solutions(string $table, TimelineSelection $selection): int
    {
        $criteria = $selection->criteria['solutions'];
        $metadata = $this->admitted($table, ITILSolution::class);
        if ($metadata === null || !in_array($criteria['itemtype'], ['Ticket', 'Change', 'Problem'], true) || !$this->plain($criteria['items_id'])) {
            return (new RecordRepository($this->manager))->countMatching($table, $criteria);
        }
        return (new TimelineRepository($this->manager))->nativeSubjectCount($metadata, $criteria['itemtype'], $criteria['items_id']);
    }

    public function followups(string $table, TimelineSelection $selection): int
    {
        $criteria = $selection->criteria['followups'];
        $metadata = $this->admitted($table, ITILFollowup::class);
        if ($metadata === null || !in_array($criteria['itemtype'], ['Ticket', 'Change', 'Problem'], true) || !$this->plain($criteria['items_id']) || !$this->plain($selection->followupAuthor)) {
            return (new RecordRepository($this->manager))->countMatching($table, $criteria);
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
            return (new RecordRepository($this->manager))->countMatching($table, $criteria);
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
        return (new DocumentRepository($this->manager))->countTimelineDocuments($type, $item, $access);
    }

    public function validations(string $table, array $criteria): int
    {
        return (new TimelineRepository($this->manager))->countValidations($table, $criteria);
    }

    private function admitted(string $table, string $class): ?ClassMetadata
    {
        if (!$this->ownedMapping || (EntityRegistry::tables()[$table] ?? null) !== $class) {
            return null;
        }
        // Deliberately retain the original ordinary manager/cache for all reads,
        // including document/validation reads. Do not load private persistent metadata.
        $metadata = $this->manager->getClassMetadata($class);
        return $metadata->isInheritanceTypeNone() && $metadata->identifier === ['id'] && $metadata->hasField('id') ? $metadata : null;
    }

    private function plain(mixed $value): bool
    {
        return $value === null || is_int($value) || is_bool($value)
            || (is_string($value) && ($value === '' || strtolower($value) === 'null' || preg_match('/^-?[0-9]+$/D', $value)));
    }
}
