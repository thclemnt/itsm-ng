<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;

/** The application and installation use one PDO-backed DBAL physical owner. */
final class MySQLManagedConnection extends Connection implements ManagedTransactionConnection
{
    use PdoTransactionOwnership;

    protected function connect(): \Doctrine\DBAL\Driver\Connection
    {
        $connection = parent::connect();
        if (!$connection instanceof \itsmng\Database\Driver\OwnedConnection) {
            $this->close();
            throw new TransactionOwnershipMismatch('MySQL ownership requires the canonical command-owning DBAL transport.');
        }
        return $connection;
    }

    public function beginTransaction(): void
    {
        $this->assertManagedTransaction();
        MySQLConnection::assertCurrentReads($this);
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
        try {
            if ($this->_conn instanceof \itsmng\Database\Driver\OwnedConnection) {
                $this->_conn->close();
            }
        } catch (\Doctrine\DBAL\Driver\Exception $error) {
            throw $this->convertException($error);
        } finally {
            parent::close();
        }
    }
}
