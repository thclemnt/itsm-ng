<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/** Let DBAL share the legacy connection, including its session and transaction. */
final class NativeDriver extends AbstractDriverMiddleware
{
    public function __construct(private object $native, private string $provider)
    {
        parent::__construct($provider === 'pgsql' ? new Driver\PgSQL\Driver() : new Driver\Mysqli\Driver());
    }

    public function connect(array $params): Driver\Connection
    {
        return $this->provider === 'pgsql'
            ? new Driver\PgSQL\Connection($this->native)
            : new Driver\Mysqli\Connection($this->native);
    }
}
