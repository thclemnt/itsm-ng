<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\V220\References;
use itsmng\Database\SchemaCheck;

require_once __DIR__ . '/ProcessorIncomingReferences.php';
require_once __DIR__ . '/ProcessorTableChecks.php';

/** Empty family reconstruction owns captured native checks, incoming IDs and one raw receipt. */
final class ComponentFamilyTable
{
    private Table $current;
    private array $ledger;
    private array $receipt;
    private ProcessorIncomingReferences $incoming;
    private ProcessorTableChecks $checks;
    private bool $attempted = false;

    public function __construct(private Connection $connection, private Schema $expected, private string $table, private string $version, private array $subjectColumns)
    {
        if ($connection->isTransactionActive() || !str_starts_with($connection->getDatabase() ?? '', 'itsm_port_')) {
            throw new LogicException('Idle disposable component reconstruction required.');
        }
        if ((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table) !== 0
            || (Ledger::state($connection, $version)['complete'] ?? false) !== true
            || (new SchemaCheck())->differences($connection, $expected) !== []) {
            throw new LogicException('Complete canonical schema, completed family and empty assignments required.');
        }
        $this->current = clone $expected->getTable($table);
        $this->ledger = $connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version');
        $receipt = $connection->fetchAssociative('SELECT * FROM ' . Ledger::TABLE . ' WHERE version = ?', [$version]);
        if ($receipt === false) {
            throw new LogicException('Capture the actual completed family receipt before DDL.');
        }
        $this->receipt = $receipt;
        // These existing captures are target-parametric: each retains the full
        // actual native vector and refuses unknown/unowned incoming references.
        $this->incoming = new ProcessorIncomingReferences($connection, $expected, $table);
        $this->checks = new ProcessorTableChecks($connection, $table);
    }

    public function legacy(string $comment, ?string $integerFlag = null, ?string $nullableOwner = null, bool $relaxFlagCheck = false): void
    {
        if ($integerFlag !== null && !array_key_exists($integerFlag, \itsmng\Database\EntityRegistry::booleanFields($this->table))) {
            throw new LogicException('Only the actual declared flag can construct this historical drift fixture.');
        }
        if ($nullableOwner !== null && !isset(\itsmng\Database\EntityRegistry::relations()[$this->table][$nullableOwner])) {
            throw new LogicException('Only an actual declared owner can construct this historical null fixture.');
        }
        $this->attempted = true;
        // A completed receipt must never survive destructive fixture DDL.
        $this->connection->delete(Ledger::TABLE, ['version' => $this->version]);
        $this->incoming->detach();
        $manager = $this->connection->createSchemaManager();
        $manager->dropTable($this->table);
        $legacy = clone $this->current;
        foreach ($legacy->getForeignKeys() as $foreign) {
            $columns = array_map(static fn (string $name): string => trim($name, '`"'), $foreign->getLocalColumns());
            if (array_intersect($columns, $this->subjectColumns) || ($nullableOwner !== null && $columns === [$nullableOwner])) {
                $legacy->removeForeignKey($foreign->getName());
            }
        }
        foreach ($legacy->getIndexes() as $index) {
            if (array_intersect(array_map(static fn (string $name): string => trim($name, '`"'), $index->getColumns()), $this->subjectColumns)) {
                $legacy->dropIndex($index->getName());
            }
        }
        foreach ($this->subjectColumns as $column) {
            $legacy->dropColumn($column);
        }
        $legacy->getColumn('items_id')->setColumnDefinition(null)->setNotnull(false)->setDefault(0)->setComment($comment);
        $legacy->getColumn('itemtype')->setNotnull(false)->setDefault(null);
        if ($integerFlag !== null) {
            $legacy->getColumn($integerFlag)->setType(\Doctrine\DBAL\Types\Type::getType('integer'))->setNotnull(false)->setDefault(0);
        }
        if ($nullableOwner !== null) {
            $legacy->getColumn($nullableOwner)->setNotnull(false);
        }
        $manager->createTable($legacy);
        $this->checks->install(false);
        if ($integerFlag !== null && $relaxFlagCheck && !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            $platform = $this->connection->getDatabasePlatform();
            $name = \itsmng\Database\BooleanDomainSchema::name($this->table, $integerFlag);
            $this->connection->executeStatement('ALTER TABLE ' . $platform->quoteIdentifier($this->table) . ' DROP '
                . ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ')
                . $platform->quoteIdentifier($name));
        }
        $this->incoming->restore();
    }

    public function restore(): void
    {
        if (!$this->attempted) {
            return;
        }
        // As in legacy(), no completed receipt may survive restoration DDL.
        $this->connection->delete(Ledger::TABLE, ['version' => $this->version]);
        $manager = $this->connection->createSchemaManager();
        $this->incoming->detach();
        if ($manager->tablesExist([$this->table])) {
            $manager->dropTable($this->table);
        }
        $manager->createTable($this->current);
        $this->checks->install(true);
        $this->incoming->restore();
        if (!$this->checks->restored() || !$this->incoming->restored()
            || (new SchemaCheck())->differences($this->connection, $this->expected) !== []) {
            throw new RuntimeException('Restore exact native component constraints and schema before its receipt.');
        }
        $unrelated = fn (array $rows): array => array_values(array_filter($rows, fn (array $row): bool => $row['version'] !== $this->version));
        $actual = $this->connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version');
        if ($unrelated($actual) !== $unrelated($this->ledger)) {
            throw new RuntimeException('Refuse unrelated history changes during component fixture restoration.');
        }
        $this->connection->delete(Ledger::TABLE, ['version' => $this->version]);
        $this->connection->insert(Ledger::TABLE, $this->receipt);
        if ($this->connection->fetchAllAssociative('SELECT * FROM ' . Ledger::TABLE . ' ORDER BY version') !== $this->ledger) {
            throw new RuntimeException('Every original raw family receipt must restore exactly.');
        }
        $this->attempted = false;
    }
}
