<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use itsmng\Database\BooleanCheckExpression;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Migration\BooleanDomains20261008;
use itsmng\Database\Migration\Ledger;

/** Preserve only the owning properties' native domains during table reconstruction. */
final class NativeBooleanFixture
{
    private array $columns = [];
    private array $checks = [];
    private array $receipt;
    private bool $mysql;
    private bool $ansiQuotes;

    public function __construct(private Connection $connection, private string $table)
    {
        $catalog = BooleanDomainSchema::catalog($connection);
        $this->mysql = $catalog['mysql'];
        $this->ansiQuotes = $catalog['ansi_quotes'];
        $this->receipt = Ledger::state($connection, BooleanDomains20261008::VERSION)
            ?? throw new LogicException('Boolean migration receipt required before fixture reconstruction');
        self::ensure($this->receipt['complete'] ?? false, 'Completed boolean domains required before reconstruction');
        foreach (EntityRegistry::booleanFields($table) as $column => $nullable) {
            $this->columns[$column] = $catalog['columns'][$table][$column]
                ?? throw new LogicException('Native boolean column missing before reconstruction: ' . $table . '.' . $column);
            if (!$this->mysql) {
                self::ensure($this->columns[$column]['data_type'] === 'boolean', 'Native PostgreSQL boolean required');
                continue;
            }
            $name = BooleanDomainSchema::name($table, $column);
            $check = $catalog['checks'][$table][$name]
                ?? throw new LogicException('Native boolean CHECK missing before reconstruction: ' . $table . '.' . $name);
            self::ensure($check['enforced'] === 'YES'
                && BooleanCheckExpression::matches($check['clause'], $column, $nullable, $this->ansiQuotes),
                'Canonical enforced boolean CHECK required before reconstruction: ' . $table . '.' . $name);
            $this->checks[$name] = $check;
        }
        self::ensure($this->columns !== [], 'Fixture table must own mapped boolean properties');
    }

    /** Restore dropped native CHECKs without replaying or altering migration receipts. */
    public function restore(): void
    {
        self::ensure(Ledger::state($this->connection, BooleanDomains20261008::VERSION) === $this->receipt,
            'Boolean migration receipt changed before fixture restoration');
        $catalog = BooleanDomainSchema::catalog($this->connection);
        self::ensure($catalog['mysql'] === $this->mysql && $catalog['ansi_quotes'] === $this->ansiQuotes,
            'Native fixture interpretation must remain unchanged');
        foreach ($this->columns as $column => $definition) {
            self::ensure(($catalog['columns'][$this->table][$column] ?? null) === $definition,
                'Reconstruction changed native boolean column: ' . $this->table . '.' . $column);
        }
        foreach ($this->checks as $name => $definition) {
            if (!isset($catalog['checks'][$this->table][$name])) {
                $this->connection->executeStatement('ALTER TABLE ' . $this->connection->quoteIdentifier($this->table)
                    . ' ADD CONSTRAINT ' . $this->connection->quoteIdentifier($name) . ' CHECK (' . $definition['clause'] . ')');
            } else {
                self::ensure($catalog['checks'][$this->table][$name] === $definition,
                    'Existing boolean CHECK changed during fixture reconstruction: ' . $this->table . '.' . $name);
            }
        }
        $restored = BooleanDomainSchema::catalog($this->connection);
        foreach ($this->checks as $name => $definition) {
            self::ensure(($restored['checks'][$this->table][$name] ?? null) === $definition,
                'Fixture did not restore the exact native boolean CHECK: ' . $this->table . '.' . $name);
        }
        self::ensure(Ledger::state($this->connection, BooleanDomains20261008::VERSION) === $this->receipt,
            'Reconstruction must preserve the completed boolean migration receipt exactly');
    }

    private static function ensure(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new LogicException($message);
        }
    }
}
