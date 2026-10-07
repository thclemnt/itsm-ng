<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\RecordRepository;

/** Private fixed record queries; only current rows/counts leave this operation. */
final class RecordReadOperation implements ReadQueryOwner
{
    use PrivateReadOwnership;

    public function row(string $table, string $column, int $id): ?array
    {
        $metadata = $this->metadata($table, $column);
        if ($this->scalar($metadata) && $metadata->getColumnName($metadata->identifier[0]) === $column) {
            return (new RecordRepository($this->manager))->scalarRow(
                $metadata->name,
                $id,
                null,
                $this->defaultIdentifiers($metadata),
                $this,
            );
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
        $metadata = $this->metadata($table);
        if ($this->scalar($metadata)) {
            return (new RecordRepository($this->manager))->matching(
                $table,
                $criteria,
                $order,
                $limit,
                $offset,
                true,
                $this->defaultIdentifiers($metadata),
                $this,
            );
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
