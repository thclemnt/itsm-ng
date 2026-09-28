<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/** Transitional PostgreSQL bridge; MySQL connections are owned directly by DBAL. */
final class NativeDriver extends AbstractDriverMiddleware
{
    public function __construct(private object $native)
    {
        parent::__construct(new Driver\PgSQL\Driver());
    }

    public function connect(array $params): Driver\Connection
    {
        return new Driver\PgSQL\Connection($this->native);
    }
}
