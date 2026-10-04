<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;

/** Commit and rollback use the existing DBAL owner's authoritative frame capability. */
final class OwnedMutationFrame
{
    private function __construct(private Connection $connection, private ManagedTransactionScope $scope, private int $level)
    {
    }

    public static function begin(Connection $connection): self
    {
        TransactionOwnership::assertManaged($connection);
        $level = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        return new self($connection, $connection->captureManagedTransactionScope(), $level);
    }

    public function assertActive(): void
    {
        $this->scope->assertActive();
        if ($this->connection->getTransactionNestingLevel() !== $this->level + 1) {
            throw new TransactionOwnershipMismatch('A callback changed the owned mutation frame depth.');
        }
    }

    public function commit(): void
    {
        $this->assertActive();
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->assertActive();
        $this->connection->rollBack();
    }

    public static function run(Connection $connection, callable $operation): mixed
    {
        $frame = self::begin($connection);
        try {
            $result = $operation();
            $frame->commit();
            return $result;
        } catch (\Throwable $primary) {
            try {
                $frame->rollBack();
            } catch (\Throwable $cleanup) {
                throw new MutationRollbackFailure($primary, $cleanup);
            }
            throw $primary;
        }
    }
}
