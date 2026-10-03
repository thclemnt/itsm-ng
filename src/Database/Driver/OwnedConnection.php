<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Driver;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** A DBAL driver's commands and results cannot retain a closed physical session. */
final class OwnedConnection implements Connection
{
    /** @var \WeakMap<OwnedStatement, true> */
    private \WeakMap $statements;
    /** @var \WeakMap<OwnedResult, true> */
    private \WeakMap $results;

    public function __construct(private ?Connection $connection)
    {
        $this->statements = new \WeakMap();
        $this->results = new \WeakMap();
    }

    public function prepare(string $sql): Statement
    {
        $statement = new OwnedStatement($this->active()->prepare($sql), \WeakReference::create($this));
        $this->statements[$statement] = true;
        return $statement;
    }

    public function query(string $sql): Result
    {
        return $this->ownResult($this->active()->query($sql));
    }

    /** @internal The originating owned statement delegates its actual result here. */
    public function ownResult(Result $result): OwnedResult
    {
        $this->active();
        $owned = new OwnedResult($result);
        $this->results[$owned] = true;
        return $owned;
    }

    public function quote(string $value): string
    {
        return $this->active()->quote($value);
    }

    public function exec(string $sql): int|string
    {
        return $this->active()->exec($sql);
    }

    public function lastInsertId(): int|string
    {
        return $this->active()->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->active()->beginTransaction();
    }

    public function commit(): void
    {
        $this->active()->commit();
    }

    public function rollBack(): void
    {
        $this->active()->rollBack();
    }

    public function getServerVersion(): string
    {
        return $this->active()->getServerVersion();
    }

    public function getNativeConnection()
    {
        return $this->active()->getNativeConnection();
    }

    public function close(): void
    {
        $primary = null;
        foreach ($this->results as $result => $_) {
            try {
                $result->close();
            } catch (\Throwable $error) {
                $primary ??= $error;
            }
        }
        try {
            foreach ($this->statements as $statement => $_) {
                try {
                    $statement->close();
                } catch (\Throwable $error) {
                    $primary ??= $error;
                }
            }
            // Logging middleware can throw from its real disconnect destructor.
            try {
                $this->connection = null;
            } catch (\Throwable $error) {
                $primary ??= $error;
            }
        } finally {
            $this->results = new \WeakMap();
            $this->statements = new \WeakMap();
        }
        if ($primary !== null) {
            throw $primary;
        }
    }

    private function active(): Connection
    {
        return $this->connection ?? throw new \LogicException('Physical DBAL command owner is closed.');
    }
}
