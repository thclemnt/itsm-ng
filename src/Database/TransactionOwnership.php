<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;

/** Never adopt a caller's unknown physical transaction through logical nesting. */
final class TransactionOwnership
{
    /**
     * @phpstan-assert ManagedTransactionConnection $connection
     * @psalm-assert ManagedTransactionConnection $connection
     */
    public static function assertManaged(Connection $connection): void
    {
        if (!$connection instanceof ManagedTransactionConnection) {
            throw new TransactionOwnershipMismatch('The supplied DBAL owner cannot establish physical transaction ownership.');
        }
        $connection->assertManagedTransaction();
    }
}
