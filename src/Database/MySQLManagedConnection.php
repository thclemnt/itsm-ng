<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;

/** The application and installation use one PDO-backed DBAL physical owner. */
final class MySQLManagedConnection extends Connection implements ManagedTransactionConnection
{
    use PdoTransactionOwnership;
}
