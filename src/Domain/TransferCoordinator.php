<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Own the database frame for one transfer, including its recursive public hooks. */
final class TransferCoordinator
{
    /** @var array<string, true> Tables inspected during this operation only. */
    private array $transactionalTables = [];

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
        return \itsmng\Database\OwnedMutationFrame::run($connection, $operation);
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
