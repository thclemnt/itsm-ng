<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use itsmng\Database\Driver\Postgres\Driver as PostgresDriver;
use itsmng\Database\Driver\Postgres\Result as PostgresResult;
use itsmng\Database\Driver\Postgres\OwnedStatement;

/** Shared PostgreSQL transaction outcomes for ORM, DBAL and the legacy adapter. */
final class PostgresConnection extends Connection implements ManagedTransactionConnection
{
    use PdoTransactionOwnership;

    private string $timezone = 'UTC';
    private ?\WeakReference $initialized = null;
    private ?\WeakMap $statements = null;

    /** A genuinely lazy factory; adapters open this owner during connect(). */
    public static function create(#[\SensitiveParameter] array $parameters, ?Configuration $configuration = null): self
    {
        $parameters['driverClass'] = PostgresDriver::class;
        $parameters['wrapperClass'] = self::class;
        unset($parameters['driver']);
        $connection = DriverManager::getConnection($parameters, $configuration);
        $connection->timezone = $parameters['timezone'] ?? 'UTC';
        $connection->setNestTransactionsWithSavepoints(true);
        return $connection;
    }

    protected function connect(): Driver\Connection
    {
        $connection = parent::connect();
        if ($this->initialized?->get() !== $connection) {
            $this->initialized = \WeakReference::create($connection);
            try {
                $statement = $connection->prepare("SELECT set_config('TimeZone', ?, false)");
                $statement->bindValue(1, $this->timezone, ParameterType::STRING);
                $statement->execute()->free();
            } catch (Driver\Exception $error) {
                $this->close();
                throw $this->convertException($error);
            }
        }
        return $connection;
    }

    public function setSessionTimezone(string $timezone): void
    {
        $this->executeQuery("SELECT set_config('TimeZone', ?, false)", [$timezone])->free();
        $this->timezone = $timezone;
    }

    /** Bound compatibility queries use the same DBAL driver and exception owner. */
    public function executeLegacyQuery(string $sql, array $values = []): Result|LegacyResult
    {
        $driver = $this->connect();
        try {
            if ($values === []) {
                $result = $driver->query($sql);
            } else {
                $statement = $driver->prepare($sql);
                foreach ($values as $index => $value) {
                    $statement->bindValue($index + 1, $value, $value === null ? ParameterType::NULL : ParameterType::STRING);
                }
                $result = $statement->execute();
            }
            return $this->legacyResult($result);
        } catch (Driver\Exception $error) {
            throw $this->convertExceptionDuringQuery($error, $sql, $values);
        }
    }

    public function prepareLegacyStatement(string $sql): Driver\Statement
    {
        try {
            $statement = new OwnedStatement($this->connect()->prepare($sql));
            $this->statements ??= new \WeakMap();
            $this->statements[$statement] = true;
            return $statement;
        } catch (Driver\Exception $error) {
            throw $this->convertExceptionDuringQuery($error, $sql);
        }
    }

    public function executeLegacyStatement(Driver\Statement $statement, string $sql, array $values, array $types): Result|LegacyResult
    {
        if ($this->statements === null || !isset($this->statements[$statement]) || !$this->isConnected()) {
            throw new \LogicException('Prepared statement does not belong to this active physical connection.');
        }
        try {
            foreach ($values as $index => $value) {
                $statement->bindValue($index + 1, $value, $value === null ? ParameterType::NULL : $types[$index]);
            }
            return $this->legacyResult($statement->execute());
        } catch (Driver\Exception $error) {
            throw $this->convertExceptionDuringQuery($error, $sql, $values, $types);
        }
    }

    public function close(): void
    {
        if ($this->statements !== null) {
            foreach ($this->statements as $statement => $_) {
                $statement->close();
            }
            $this->statements = null;
        }
        $this->initialized = null;
        parent::close();
    }

    private function legacyResult(Driver\Result $driverResult): Result|LegacyResult
    {
        $result = new Result($driverResult, $this);
        if ($result->columnCount() === 0) {
            return $result;
        }
        if (!$driverResult instanceof PostgresResult) {
            $result->free();
            throw new \LogicException('PostgreSQL legacy results require native metadata; result middleware must preserve the typed driver result.');
        }
        return new LegacyResult($result, $driverResult->legacyRow(...));
    }

    public function commit(): void
    {
        // PostgreSQL accepts COMMIT on an aborted transaction as a successful
        // ROLLBACK. Refuse before parent::commit() can discard DBAL's nesting,
        // so the caller can still roll back. Savepoint recovery clears the
        // server failure naturally; no per-query failure state is retained.
        if ($this->isTransactionActive()) {
            $this->assertCommittable();
        }
        parent::commit();
    }

    /** Inspect the current physical transaction, including legacy raw BEGIN. */
    public function assertCommittable(): void
    {
        $this->executeQuery('SELECT 1')->free();
    }
}
