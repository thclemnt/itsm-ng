<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\Migration\BooleanDomains20261008;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\LegacyToOrm;

/** Current CHECKs must not preempt a historical migration's bad-data audit. */
final class HistoricalBooleanChecks
{
    private array $checks = [];
    private array $catalog;
    private array $receipt;
    private bool $detached = false;

    /** @param array<string, list<string>> $fields The tested migration's own frozen flag scope. */
    public function __construct(private Connection $connection, array $fields)
    {
        $this->assertIdle();
        if (!str_starts_with($connection->getDatabase() ?? '', 'itsm_port_') || !Ledger::assertTransactional($connection)) {
            throw new RuntimeException('Installed disposable historical flag fixture with transactional ledger required');
        }
        $this->catalog = BooleanDomainSchema::catalog($connection);
        $row = $connection->fetchAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' WHERE version = ?', [BooleanDomains20261008::VERSION]);
        if (!$row || !(json_decode($row['state'], true, flags: JSON_THROW_ON_ERROR)['complete'] ?? false)) {
            throw new RuntimeException('Historical flag fixture requires completed current Boolean domain history');
        }
        $this->receipt = $row;
        if ($this->catalog['mysql']) {
            foreach ($fields as $table => $columns) {
                foreach ($columns as $column) {
                    $name = BooleanDomainSchema::name($table, $column);
                    $check = $this->catalog['checks'][$table][$name] ?? null;
                    if (!$check || $check['enforced'] !== 'YES') {
                        throw new RuntimeException('Historical flag fixture requires enforced current CHECK: ' . $table . '.' . $name);
                    }
                    $this->checks[$table][$name] = $check;
                }
            }
        }
    }

    public function detach(): void
    {
        $this->assertIdle();
        // Set before the first DDL: finally must repair even partial setup.
        $this->detached = true;
        $platform = $this->connection->getDatabasePlatform();
        foreach ($this->checks as $table => $checks) {
            foreach ($checks as $name => $check) {
                $this->connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($table)
                    . ($platform instanceof MySQLPlatform ? ' DROP CHECK ' : ' DROP CONSTRAINT ')
                    . $platform->quoteIdentifier($name));
            }
        }
        $this->connection->delete(LegacyToOrm::LEDGER, ['version' => BooleanDomains20261008::VERSION]);
    }

    public function restore(): void
    {
        if (!$this->detached) {
            return;
        }
        $this->assertIdle();
        $platform = $this->connection->getDatabasePlatform();
        $current = BooleanDomainSchema::catalog($this->connection);
        foreach ($this->checks as $table => $checks) {
            foreach ($checks as $name => $check) {
                if (!isset($current['checks'][$table][$name])) {
                    $this->connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($table)
                        . ' ADD CONSTRAINT ' . $platform->quoteIdentifier($name) . ' CHECK (' . $check['clause'] . ')'
                        . ($platform instanceof MySQLPlatform ? ' ENFORCED' : ''));
                }
            }
        }
        // Retain the original receipt bytes, rather than manufacturing completion.
        $this->connection->delete(LegacyToOrm::LEDGER, ['version' => BooleanDomains20261008::VERSION]);
        $this->connection->insert(LegacyToOrm::LEDGER, $this->receipt);
    }

    public function restored(): bool
    {
        return BooleanDomainSchema::catalog($this->connection)['checks'] === $this->catalog['checks']
            && $this->connection->fetchAssociative('SELECT * FROM ' . LegacyToOrm::LEDGER . ' WHERE version = ?', [BooleanDomains20261008::VERSION]) === $this->receipt;
    }

    private function assertIdle(): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new RuntimeException('Historical Boolean CHECK DDL requires an idle disposable connection');
        }
    }
}
