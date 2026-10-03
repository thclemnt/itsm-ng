<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Driver;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;

/** The owner can release a retained result without changing ordinary cursor semantics. */
final class OwnedResult implements Result
{
    public function __construct(private ?Result $result)
    {
    }

    public function fetchNumeric(): array|false
    {
        return $this->active()->fetchNumeric();
    }

    public function fetchAssociative(): array|false
    {
        return $this->active()->fetchAssociative();
    }

    public function fetchOne(): mixed
    {
        return $this->active()->fetchOne();
    }

    public function fetchAllNumeric(): array
    {
        return $this->active()->fetchAllNumeric();
    }

    public function fetchAllAssociative(): array
    {
        return $this->active()->fetchAllAssociative();
    }

    public function fetchFirstColumn(): array
    {
        return $this->active()->fetchFirstColumn();
    }

    public function rowCount(): int|string
    {
        return $this->active()->rowCount();
    }

    public function columnCount(): int
    {
        return $this->active()->columnCount();
    }

    public function getColumnName(int $index): string
    {
        return $this->active()->getColumnName($index);
    }

    public function free(): void
    {
        if ($this->result !== null) {
            $this->release($this->result);
        }
    }

    /** @internal Physical owner closure releases all retained native references. */
    public function close(): void
    {
        $result = $this->result;
        $this->result = null;
        if ($result !== null) {
            $this->release($result);
        }
    }

    private function release(Result $result): void
    {
        try {
            $result->free();
        } catch (\PDOException $error) {
            throw PdoDriverException::new($error);
        }
    }

    private function active(): Result
    {
        return $this->result ?? throw new \LogicException('Result physical owner is closed.');
    }
}
