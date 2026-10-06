<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Own the database frame for one transfer, including its recursive public hooks. */
final class TransferCoordinator
{
    /** @var array<string, true> Tables inspected during this operation only. */
    private array $transactionalTables = [];
    private ?\Closure $guard = null;

    public function __construct(private \DBAdapter $database)
    {
    }

    public function run(callable $operation): mixed
    {
        global $DB;
        if ($DB !== $this->database || $this->database->isSlave()) {
            throw new TransferCancelled('Transfer requires the supplied active writer connection');
        }
        $this->database->assertManagedTransaction();
        $connection = $this->database->getDoctrineConnection();
        return \itsmng\Database\OwnedMutationFrame::run($connection, function () use ($connection, $operation): mixed {
            $scope = $connection->captureManagedTransactionScope();
            $level = $connection->getTransactionNestingLevel();
            $this->guard = function () use ($connection, $scope, $level): void {
                if (($GLOBALS['DB'] ?? null) !== $this->database || $this->database->isSlave()
                    || $this->database->getDoctrineConnection() !== $connection) {
                    throw new \itsmng\Database\TransactionOwnershipMismatch('A transfer callback replaced its active writer.');
                }
                $scope->assertActive();
                if ($connection->getTransactionNestingLevel() !== $level) {
                    throw new \itsmng\Database\TransactionOwnershipMismatch('A transfer callback changed its owned frame depth.');
                }
            };
            try {
                $result = $operation();
                $this->assertActive();
                return $result;
            } finally {
                $this->guard = null;
            }
        });
    }

    /** Check immediately after each public callback, before another mutation. */
    public function assertActive(): void
    {
        if ($this->guard === null) {
            throw new \LogicException('Transfer has no active owned frame.');
        }
        ($this->guard)();
    }

    /** A selected MyISAM parent cannot participate in this rollback contract. */
    public function assertTransactionalStorage(string $table): void
    {
        $connection = $this->database->getDoctrineConnection();
        if (isset($this->transactionalTables[$table])
            || !$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            return;
        }
        $engine = $connection->fetchOne(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        if (!is_string($engine) || strcasecmp($engine, 'InnoDB') !== 0) {
            throw new TransferCancelled('Transfer requires InnoDB storage for selected table ' . $table);
        }
        $this->transactionalTables[$table] = true;
    }
}
