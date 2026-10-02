<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Scalar ORM results at the legacy dropdown boundary, with no driver dependency. */
final class RowIterator implements \Iterator, \Countable
{
    private int $position = 0;
    private ?array $row = null;

    public function __construct(private array $rows)
    {
        $this->rows = array_values($rows);
    }

    public function rewind(): void
    {
        $this->position = 0;
        $this->next();
    }

    public function current(): mixed
    {
        return $this->row;
    }

    public function key(): mixed
    {
        return $this->row['id'] ?? $this->position - 1;
    }

    /** Existing callers fetch the first row with next(), without an initial rewind. */
    #[\ReturnTypeWillChange]
    public function next()
    {
        return $this->row = $this->rows[$this->position++] ?? null;
    }

    public function valid(): bool
    {
        return $this->row !== null;
    }

    public function numrows(): int
    {
        return $this->count();
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
