<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\EntityRegistry;

/** Capture actual owned native checks before this empty-table reconstruction fixture. */
final class ProcessorTableChecks
{
    private array $original;
    private string $subject;

    public function __construct(private Connection $connection, private string $table)
    {
        if ($connection->isTransactionActive() || !str_starts_with($connection->getDatabase() ?? '', 'itsm_port_')) {
            throw new LogicException('Idle disposable processor CHECK fixture required.');
        }
        $this->subject = $table . '_typed_item_kind';
        $this->original = $this->read();
        $owned = [$this->subject];
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            foreach (array_keys(EntityRegistry::booleanFields($table)) as $column) {
                $owned[] = BooleanDomainSchema::name($table, $column);
            }
        }
        $actual = array_keys($this->original);
        sort($owned);
        sort($actual);
        if ($actual !== $owned) {
            throw new LogicException('Complete owned native processor checks required; refuse unknown or missing checks before DDL.');
        }
    }

    /** DBAL createTable omits CHECKs; replay actual captured clauses, not current metadata. */
    public function install(bool $includeSubject): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $current = $this->read();
        foreach ($this->original as $name => $check) {
            if (!$includeSubject && $name === $this->subject) {
                continue;
            }
            if (isset($current[$name])) {
                if ($current[$name] !== $check) {
                    throw new RuntimeException('Processor fixture native CHECK changed: ' . $name);
                }
                continue;
            }
            $this->connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($this->table)
                . ' ADD CONSTRAINT ' . $platform->quoteIdentifier($name) . ' ' . $check['definition']
                . ($platform instanceof MySQLPlatform ? ' ENFORCED' : ''));
        }
        $expected = $this->original;
        if (!$includeSubject) {
            unset($expected[$this->subject]);
        }
        if ($this->read() !== $expected) {
            throw new RuntimeException('Processor fixture did not reproduce every captured native CHECK exactly.');
        }
    }

    public function restored(): bool
    {
        return $this->read() === $this->original;
    }

    private function read(): array
    {
        $checks = [];
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT conname AS name, pg_get_constraintdef(oid) AS definition, convalidated FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND contype='c' ORDER BY conname",
                [$this->connection->getDatabasePlatform()->quoteIdentifier($this->table)]
            );
            foreach ($rows as $row) {
                if (!in_array($row['convalidated'], [true, 1, '1', 't'], true)) {
                    throw new LogicException('Validated native processor CHECK required: ' . $row['name']);
                }
                $checks[$row['name']] = ['definition' => $row['definition'], 'enforced' => true];
            }
        } else {
            foreach (BooleanDomainSchema::checks($this->connection, $this->table)[$this->table] ?? [] as $name => $row) {
                if ($row['enforced'] !== 'YES') {
                    throw new LogicException('Enforced native processor CHECK required: ' . $name);
                }
                $checks[$name] = ['definition' => 'CHECK (' . $row['clause'] . ')', 'enforced' => true];
            }
        }
        ksort($checks);
        return $checks;
    }
}
