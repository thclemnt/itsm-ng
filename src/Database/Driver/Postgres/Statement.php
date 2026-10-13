<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Driver\Postgres;

use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\ParameterType;
use PDO;
use PDOException;
use PDOStatement;

/** Keep the real PDO statement available to its result's column metadata. */
final class Statement implements DriverStatement
{
    public function __construct(private readonly PDOStatement $statement)
    {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $nativeType = match ($type) {
            ParameterType::NULL => PDO::PARAM_NULL,
            ParameterType::INTEGER => PDO::PARAM_INT,
            ParameterType::BOOLEAN => PDO::PARAM_BOOL,
            ParameterType::BINARY, ParameterType::LARGE_OBJECT => PDO::PARAM_LOB,
            ParameterType::STRING, ParameterType::ASCII => PDO::PARAM_STR,
        };
        try {
            $this->statement->bindValue($param, $value, $nativeType);
        } catch (PDOException $error) {
            throw Exception::new($error);
        }
    }

    public function execute(): Result
    {
        try {
            $this->statement->execute();
            return new Result($this->statement);
        } catch (PDOException $error) {
            throw Exception::new($error);
        }
    }
}
