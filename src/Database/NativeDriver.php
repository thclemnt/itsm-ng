<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/** Transitional PostgreSQL bridge; MySQL connections are owned directly by DBAL. */
final class NativeDriver extends AbstractDriverMiddleware
{
    private bool $transferred = false;

    public function __construct(private readonly object $native)
    {
        parent::__construct(new Driver\PgSQL\Driver());
    }

    public function connect(array $params): Driver\Connection
    {
        $connection = new Driver\PgSQL\Connection($this->native);
        // This bridge is bound to one physical handle for its lifetime. DBAL
        // can automatically close the owning driver after connection loss;
        // isConnected() then no longer describes who owns that handle's close.
        $this->transferred = true;
        return $connection;
    }

    public function hasTransferredConnection(): bool
    {
        return $this->transferred;
    }
}
