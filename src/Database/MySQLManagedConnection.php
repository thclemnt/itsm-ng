<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;

/** The application and installation use one PDO-backed DBAL physical owner. */
final class MySQLManagedConnection extends Connection implements ManagedTransactionConnection
{
    use PdoTransactionOwnership;

    public function beginTransaction(): void
    {
        $this->assertManagedTransaction();
        parent::beginTransaction();
        $this->recordManagedFrame();
    }

    public function commit(): void
    {
        try {
            parent::commit();
        } finally {
            $this->reconcileManagedFrames();
        }
    }

    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->reconcileManagedFrames();
        }
    }

    public function close(): void
    {
        $this->resetManagedFrames();
        parent::close();
    }
}
