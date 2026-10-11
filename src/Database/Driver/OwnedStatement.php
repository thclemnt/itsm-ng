<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Driver;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use LogicException;
use WeakReference;

/** Retained DBAL statements delegate results to their exact physical owner. */
final class OwnedStatement implements Statement
{
    public function __construct(private ?Statement $statement, private WeakReference $owner)
    {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->active()->bindValue($param, $value, $type);
    }

    public function execute(): Result
    {
        $owner = $this->owner->get();
        if (!$owner instanceof OwnedConnection) {
            throw new LogicException('Prepared command owner no longer exists.');
        }
        return $owner->ownResult($this->active()->execute());
    }

    public function close(): void
    {
        $this->statement = null;
    }

    private function active(): Statement
    {
        return $this->statement ?? throw new LogicException('Prepared command owner is closed.');
    }
}
