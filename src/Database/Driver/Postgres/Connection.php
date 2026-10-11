<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Driver\Postgres;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\PDO\Exception;
use itsmng\Database\PostgresParameters;
use PDO;
use PDOException;

/** Extend the driver result boundary; lifecycle remains the vendor owner's. */
final class Connection extends AbstractConnectionMiddleware
{
    private readonly PDO $pdo;

    public function __construct(DriverConnection $connection)
    {
        parent::__construct($connection);
        $this->pdo = $connection->getNativeConnection();
    }

    public function prepare(string $sql): Statement
    {
        try {
            return new Statement($this->pdo->prepare(PostgresParameters::prepare($sql)));
        } catch (PDOException $error) {
            throw Exception::new($error);
        }
    }

    public function query(string $sql): Result
    {
        try {
            return new Result($this->pdo->query(PostgresParameters::prepare($sql)));
        } catch (PDOException $error) {
            throw Exception::new($error);
        }
    }
}
