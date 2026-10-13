<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Driver\Postgres;

use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\ParameterType;
use LogicException;

/** A retained compatibility command cannot outlive its physical DBAL owner. */
final class OwnedStatement implements DriverStatement
{
    public function __construct(private ?DriverStatement $statement)
    {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->active()->bindValue($param, $value, $type);
    }

    public function execute(): DriverResult
    {
        return $this->active()->execute();
    }

    public function close(): void
    {
        $this->statement = null;
    }

    private function active(): DriverStatement
    {
        return $this->statement ?? throw new LogicException('Prepared statement owner is closed.');
    }
}
