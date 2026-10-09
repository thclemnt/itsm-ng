<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\RecordRepository;

/** Private fixed record queries; only current rows/counts leave this operation. */
final class RecordReadOperation implements ReadQueryOwner
{
    use PrivateReadOwnership;

    /** Explicit scalar callers never dispatch entity postLoad, including extension mappings. */
    public function scalarRow(string $table, int $id): ?array
    {
        $metadata = $this->metadata($table);
        return (new RecordRepository($this->manager))->scalarRow(
            $metadata->name,
            $id,
            $this->defaultIdentifiers($metadata),
            $this,
        );
    }

    public function row(string $table, string $column, int $id): ?array
    {
        $metadata = $this->metadata($table, $column);
        if ($this->scalar($metadata) && $metadata->getColumnName($metadata->identifier[0]) === $column) {
            return (new RecordRepository($this->manager))->scalarRow(
                $metadata->name,
                $id,
                $this->defaultIdentifiers($metadata),
                $this,
            );
        }
        // Alternate legacy indexes still return one complete row. Admit only
        // canonical scalar declarations; custom repositories and lifecycle hooks
        // retain their independently owned entity lookup below.
        $identifiers = $this->defaultIdentifiers($metadata);
        if ($this->ownedMapping && $this->scalar($metadata) && $identifiers !== null
            && $metadata->isInheritanceTypeNone() && $metadata->customRepositoryClassName === null
            && $this->manager->getUnitOfWork()->size() === 0) {
            $knownColumn = isset($metadata->fieldNames[$column]);
            $supportedReferences = true;
            foreach ($metadata->associationMappings as $association) {
                if (!$association->isToOneOwningSide()) {
                    continue;
                }
                if (count($association->joinColumns) !== 1
                    || ($identifiers[$association->targetEntity]['column'] ?? null) !== $association->joinColumns[0]->referencedColumnName) {
                    $supportedReferences = false;
                    break;
                }
                $knownColumn = $knownColumn || $association->joinColumns[0]->name === $column;
            }
            if ($knownColumn && $supportedReferences) {
                return (new RecordRepository($this->manager))->scalarRow(
                    $metadata->name,
                    $id,
                    $identifiers,
                    $this,
                    $column
                );
            }
        }
        // Entity callbacks must receive wholly local metadata, not private cached
        // metadata whose backend alone was detached after it had already loaded.
        $fallback = $this->fallbackManager();
        try {
            return (new RecordRepository($fallback))->find($table, $column, $id);
        } finally {
            $fallback->clear();
        }
    }

    public function matching(string $table, array $criteria, array|string $order, ?int $limit, int $offset): array
    {
        $result = $this->matchingResult($table, $criteria, $order, $limit, $offset);
        if ($result instanceof UnsupportedCriteria) {
            throw $result;
        }
        return $result;
    }

    /** @internal Only MappedReads carries a compiler rejection outside its already admitted shared scope. */
    public function matchingResult(string $table, array $criteria, array|string $order, ?int $limit, int $offset): array|UnsupportedCriteria
    {
        $metadata = $this->metadata($table);
        if ($this->scalar($metadata)) {
            $result = (new RecordRepository($this->manager))->matchingResult(
                $table,
                $criteria,
                $order,
                $limit,
                $offset,
                true,
                $this->defaultIdentifiers($metadata),
                $this,
            );
            if ($result instanceof UnsupportedCriteria && !$this->sharedManager) {
                // Supplied/custom/reentrant managers retain the original exception boundary.
                throw $result;
            }
            return $result;
        }
        $fallback = $this->fallbackManager();
        try {
            return (new RecordRepository($fallback))->matching($table, $criteria, $order, $limit, $offset);
        } finally {
            $fallback->clear();
        }
    }

    public function countMatching(string $table, array $criteria): int
    {
        $this->metadata($table);
        return (new RecordRepository($this->manager))->countMatching($table, $criteria, true, $this);
    }

}
