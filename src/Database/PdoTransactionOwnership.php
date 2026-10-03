<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Driver\PDO\Exception;

/** Physical state stays inside the DBAL owner; never infer it from application SQL. */
trait PdoTransactionOwnership
{
    public function assertManagedTransaction(): void
    {
        $native = $this->getNativeConnection();
        if (!$native instanceof \PDO) {
            throw new TransactionOwnershipMismatch('The supplied transaction owner requires its current PDO connection.');
        }
        try {
            $physical = $native->inTransaction();
        } catch (\PDOException $error) {
            throw $this->convertException(Exception::new($error));
        }
        if ($physical !== ($this->getTransactionNestingLevel() > 0)) {
            throw new TransactionOwnershipMismatch('Physical transaction state disagrees with DBAL ownership; finish the caller transaction before this operation.');
        }
    }
}
