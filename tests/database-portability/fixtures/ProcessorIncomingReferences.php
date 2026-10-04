<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;

/** Own only canonical incoming ID constraints during empty processor reconstruction. */
final class ProcessorIncomingReferences
{
    private string $schema;
    private array $original;
    private array $consumers = [];
    private array $attempted = [];

    public function __construct(private Connection $connection, Schema $expected, private string $target)
    {
        $this->assertIdle();
        $this->schema = (string)$connection->fetchOne($this->postgres() ? 'SELECT current_schema()' : 'SELECT DATABASE()');
        if (!$this->postgres() && (int)$connection->fetchOne('SELECT @@SESSION.foreign_key_checks') !== 1) {
            throw new LogicException('Processor reconstruction requires active native foreign-key enforcement.');
        }
        $canonical = [];
        foreach ($expected->getTables() as $table) {
            foreach ($table->getForeignKeys() as $foreign) {
                if (trim($foreign->getForeignTableName(), '`"') !== $target) {
                    continue;
                }
                $columns = array_map(static fn (string $name): string => trim($name, '`"'), $foreign->getLocalColumns());
                if (count($columns) !== 1 || $foreign->getForeignColumns() !== ['id']
                    || $table->getColumn($columns[0])->getNotnull()
                    || $foreign->getOnUpdateAction() !== ReferentialAction::RESTRICT || $foreign->getOnDeleteAction() !== ReferentialAction::RESTRICT) {
                    throw new LogicException('Processor reconstruction requires canonical nullable incoming ID ownership.');
                }
                $key = $this->key($this->schema, $table->getName(), $foreign->getName());
                $canonical[$key] = ['schema' => $this->schema, 'table' => $table->getName(),
                    'name' => $foreign->getName(), 'columns' => $columns, 'referenced_columns' => ['id']];
            }
        }
        $this->original = $this->read();
        ksort($canonical);
        if (!$canonical || array_keys($this->original) !== array_keys($canonical)) {
            throw new LogicException('Refuse missing, unknown or custom incoming processor constraints before fixture DDL.');
        }
        foreach ($this->original as $key => $reference) {
            foreach ($canonical[$key] as $field => $value) {
                if ($reference[$field] !== $value) {
                    throw new LogicException('Incoming processor constraint differs from owning metadata: ' . $reference['name']);
                }
            }
            // An empty target cannot have a populated incoming owner; never clear consumer cells.
            if ((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $this->table($reference)
                . ' WHERE ' . $connection->quoteIdentifier($reference['columns'][0]) . ' IS NOT NULL') !== 0) {
                throw new LogicException('Processor reconstruction cannot detach a populated incoming owner.');
            }
            $this->consumers[$reference['table']] = $this->consumerRows($reference);
        }
    }

    /** Refuse any unowned change before detaching the exact captured core graph. */
    public function detach(): void
    {
        $this->assertIdle();
        $actual = $this->read();
        $this->assertOwnedState($actual);
        $this->assertConsumersUnchanged();
        foreach ($this->original as $key => $reference) {
            if (!isset($actual[$key])) {
                continue; // Only an earlier attempted detach may be absent during cleanup.
            }
            // An error can follow nontransactional DDL; cleanup discovers actual native state.
            $this->attempted[$key] = true;
            $this->connection->executeStatement('ALTER TABLE ' . $this->table($reference) . ' DROP '
                . ($this->postgres() ? 'CONSTRAINT ' : 'FOREIGN KEY ')
                . $this->connection->quoteIdentifier($reference['name']));
        }
        if ($this->read() !== []) {
            throw new RuntimeException('Only captured core processor references may be detached.');
        }
    }

    /** Replay captured native clauses, not a fresh metadata-generated replacement. */
    public function restore(): void
    {
        $this->assertIdle();
        $actual = $this->read();
        $this->assertOwnedState($actual);
        $this->assertConsumersUnchanged();
        $errors = [];
        foreach ($this->original as $key => $reference) {
            if (isset($actual[$key])) {
                continue;
            }
            try {
                $this->connection->executeStatement('ALTER TABLE ' . $this->table($reference) . ' ADD CONSTRAINT '
                    . $this->connection->quoteIdentifier($reference['name']) . ' ' . $reference['definition']);
            } catch (Throwable $error) {
                $errors[] = $error;
            }
        }
        try {
            if (!$this->restored()) {
                throw new RuntimeException('Processor incoming constraints and all consumer rows must restore exactly.');
            }
        } catch (Throwable $error) {
            $errors[] = $error;
        }
        if ($errors) {
            throw new RuntimeException('Owned core processor constraint restoration failed.', previous: $errors[0]);
        }
        $this->attempted = [];
    }

    private function assertOwnedState(array $actual): void
    {
        foreach ($actual as $key => $reference) {
            if (!isset($this->original[$key]) || $reference !== $this->original[$key]) {
                throw new RuntimeException('Refuse changed or unknown incoming processor constraint during restoration.');
            }
        }
        foreach ($this->original as $key => $reference) {
            if (!isset($actual[$key]) && !isset($this->attempted[$key])) {
                throw new RuntimeException('Refuse disappearance of an unowned incoming processor constraint.');
            }
        }
    }

    private function assertConsumersUnchanged(): void
    {
        foreach ($this->original as $reference) {
            if ($this->consumerRows($reference) !== $this->consumers[$reference['table']]) {
                throw new RuntimeException('Refuse changes to processor incoming consumer rows.');
            }
        }
    }

    public function restored(): bool
    {
        if ($this->read() !== $this->original) {
            return false;
        }
        foreach ($this->original as $reference) {
            if ($this->consumerRows($reference) !== $this->consumers[$reference['table']]) {
                return false;
            }
        }
        return true;
    }

    private function assertIdle(): void
    {
        if ($this->connection->isTransactionActive() || !str_starts_with($this->connection->getDatabase() ?? '', 'itsm_port_')) {
            throw new LogicException('Idle disposable processor reconstruction connection required.');
        }
        if (isset($this->schema) && $this->connection->fetchOne($this->postgres() ? 'SELECT current_schema()' : 'SELECT DATABASE()') !== $this->schema) {
            throw new LogicException('Refuse a changed processor fixture schema/database before native DDL.');
        }
    }

    private function postgres(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function key(string $schema, string $table, string $name): string
    {
        return json_encode([$schema, $table, $name], JSON_THROW_ON_ERROR);
    }

    private function table(array $reference): string
    {
        return $this->connection->quoteIdentifier($reference['schema']) . '.' . $this->connection->quoteIdentifier($reference['table']);
    }

    private function consumerRows(array $reference): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM ' . $this->table($reference) . ' ORDER BY id');
    }

    /** Inspect every visible referencing schema/database, not only application tables. */
    private function read(): array
    {
        $references = [];
        if ($this->postgres()) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT ns.nspname AS source_schema, src.relname AS source_table, f.conname AS name,
                    pg_get_constraintdef(f.oid) AS definition, f.convalidated, f.condeferrable, f.condeferred,
                    f.confmatchtype, f.confupdtype, f.confdeltype,
                    array_to_json(ARRAY(SELECT a.attname::text FROM unnest(f.conkey) WITH ORDINALITY k(num, ordinal)
                        JOIN pg_catalog.pg_attribute a ON a.attrelid=f.conrelid AND a.attnum=k.num ORDER BY k.ordinal)) AS columns,
                    array_to_json(ARRAY(SELECT a.attname::text FROM unnest(f.confkey) WITH ORDINALITY k(num, ordinal)
                        JOIN pg_catalog.pg_attribute a ON a.attrelid=f.confrelid AND a.attnum=k.num ORDER BY k.ordinal)) AS referenced_columns
                 FROM pg_catalog.pg_constraint f
                 JOIN pg_catalog.pg_class src ON src.oid=f.conrelid
                 JOIN pg_catalog.pg_namespace ns ON ns.oid=src.relnamespace
                 WHERE f.contype='f' AND f.confrelid=to_regclass(?) ORDER BY ns.nspname, src.relname, f.conname",
                [$this->connection->quoteIdentifier($this->schema) . '.' . $this->connection->quoteIdentifier($this->target)]
            );
            foreach ($rows as $row) {
                if (!in_array($row['convalidated'], [true, 1, '1', 't'], true)
                    || !in_array($row['condeferrable'], [false, 0, '0', 'f'], true)
                    || !in_array($row['condeferred'], [false, 0, '0', 'f'], true)
                    || $row['confmatchtype'] !== 's' || $row['confupdtype'] !== 'r' || $row['confdeltype'] !== 'r') {
                    throw new LogicException('Validated nondeferrable RESTRICT incoming processor ownership required.');
                }
                $reference = ['schema' => $row['source_schema'], 'table' => $row['source_table'], 'name' => $row['name'],
                    'columns' => json_decode($row['columns'], true, flags: JSON_THROW_ON_ERROR),
                    'referenced_columns' => json_decode($row['referenced_columns'], true, flags: JSON_THROW_ON_ERROR),
                    'definition' => $row['definition'], 'native' => array_intersect_key($row, array_flip([
                        'convalidated', 'condeferrable', 'condeferred', 'confmatchtype', 'confupdtype', 'confdeltype'
                    ]))];
                $references[$this->key($reference['schema'], $reference['table'], $reference['name'])] = $reference;
            }
        } else {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT k.TABLE_SCHEMA AS source_schema, k.TABLE_NAME AS source_table, k.CONSTRAINT_NAME AS name,
                    k.COLUMN_NAME AS local_column, k.REFERENCED_COLUMN_NAME AS referenced_column,
                    k.ORDINAL_POSITION AS ordinal_position, k.POSITION_IN_UNIQUE_CONSTRAINT AS referenced_position,
                    r.MATCH_OPTION AS match_option, r.UPDATE_RULE AS update_rule, r.DELETE_RULE AS delete_rule
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
                    AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
                 WHERE k.REFERENCED_TABLE_SCHEMA=? AND k.REFERENCED_TABLE_NAME=?
                 ORDER BY k.TABLE_SCHEMA, k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION",
                [$this->schema, $this->target]
            );
            foreach ($rows as $row) {
                $key = $this->key($row['source_schema'], $row['source_table'], $row['name']);
                if (isset($references[$key]) || (int)$row['ordinal_position'] !== 1 || (int)$row['referenced_position'] !== 1
                    || $row['match_option'] !== 'NONE' || $row['update_rule'] !== 'RESTRICT' || $row['delete_rule'] !== 'RESTRICT') {
                    throw new LogicException('Single-column RESTRICT incoming processor ownership required.');
                }
                $definition = 'FOREIGN KEY (' . $this->connection->quoteIdentifier($row['local_column']) . ') REFERENCES '
                    . $this->connection->quoteIdentifier($this->schema) . '.' . $this->connection->quoteIdentifier($this->target)
                    . ' (' . $this->connection->quoteIdentifier($row['referenced_column']) . ') ON UPDATE '
                    . $row['update_rule'] . ' ON DELETE ' . $row['delete_rule'];
                $references[$key] = ['schema' => $row['source_schema'], 'table' => $row['source_table'], 'name' => $row['name'],
                    'columns' => [$row['local_column']], 'referenced_columns' => [$row['referenced_column']],
                    'definition' => $definition, 'native' => array_intersect_key($row, array_flip([
                        'ordinal_position', 'referenced_position', 'match_option', 'update_rule', 'delete_rule'
                    ]))];
            }
        }
        ksort($references);
        return $references;
    }
}
