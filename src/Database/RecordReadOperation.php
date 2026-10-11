<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\RecordRepository;

/** Private fixed record queries; only current rows/counts leave this operation. */
final class RecordReadOperation implements ReadQueryOwner
{
    use PrivateReadOwnership;

    /** Complete current rows without managed records. */
    public function scalarRow(string $table, int $id): ?array
    {
        $this->beginRead();
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
        $this->beginRead();
        $metadata = $this->metadata($table, $column);
        $identifier = $metadata->getSingleIdentifierFieldName();
        return (new RecordRepository($this->manager))->scalarRow(
            $metadata->name,
            $id,
            $this->defaultIdentifiers($metadata),
            $this,
            $metadata->getColumnName($identifier) === $column ? null : $column
        );
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
        $this->beginRead();
        $metadata = $this->metadata($table);
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
            // Independent supplied and reentrant owners retain their exception boundary.
            throw $result;
        }
        return $result;
    }

    public function countMatching(string $table, array $criteria): int
    {
        $this->beginRead();
        $this->metadata($table);
        return (new RecordRepository($this->manager))->countMatching($table, $criteria, true, $this);
    }

}
