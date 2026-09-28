<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Result;

/** Seekable result for legacy iterators. New repositories use Doctrine results directly. */
final class LegacyResult
{
    private array $names = [];
    private array $rows;
    private int $position = 0;
    public readonly int $field_count;
    public readonly int $num_rows;

    public function __construct(Result $result)
    {
        try {
            $this->field_count = $result->columnCount();
            for ($i = 0; $i < $this->field_count; $i++) {
                $this->names[] = $result->getColumnName($i);
            }
            $this->rows = $result->fetchAllNumeric();
            $this->num_rows = count($this->rows);
        } finally {
            $result->free();
        }
    }

    public function fetch_row(): ?array
    {
        return $this->rows[$this->position++] ?? null;
    }

    public function fetch_assoc(): ?array
    {
        $row = $this->fetch_row();
        return $row === null ? null : array_combine($this->names, $row);
    }

    public function fetch_array(): ?array
    {
        $row = $this->fetch_row();
        return $row === null ? null : $row + array_combine($this->names, $row);
    }

    public function fetch_object(): ?object
    {
        $row = $this->fetch_assoc();
        return $row === null ? null : (object)$row;
    }

    public function data_seek(int $offset): bool
    {
        if ($offset < 0 || $offset >= $this->num_rows) {
            return false;
        }
        $this->position = $offset;
        return true;
    }

    public function fieldName(int $index): string
    {
        return $this->names[$index] ?? throw new \OutOfBoundsException('Invalid result column');
    }

    public function free(): bool
    {
        $this->rows = [];
        return true;
    }
}
