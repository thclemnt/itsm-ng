<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;

/** Shared PostgreSQL transaction outcomes for ORM, DBAL and the legacy adapter. */
final class PostgresConnection extends Connection
{
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
