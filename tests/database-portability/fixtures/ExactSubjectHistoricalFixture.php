<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\Migration\ExactDiscriminators20261010;
use itsmng\Database\Migration\History;
use itsmng\Database\Migration\LegacyToOrm;

/** Disposable native fixture; snapshots real schema/receipt before any alteration. */
final class ExactSubjectHistoricalFixture
{
    private array $original = [];
    private array|false $receipt;
    private bool $detached = false;

    public function __construct(private Connection $connection)
    {
        if ($connection->getTransactionNestingLevel() !== 0 || History::pendingVersions($connection) !== []) {
            throw new LogicException('An idle complete disposable history is required before historical fixture setup.');
        }
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $database = $connection->fetchOne($platform instanceof AbstractMySQLPlatform ? 'SELECT DATABASE()' : 'SELECT current_database()');
        if (!is_string($database) || !str_starts_with($database, 'itsm_port_')) {
            throw new LogicException('Historical subject fixture requires a disposable database.');
        }
        $this->receipt = $connection->fetchAssociative('SELECT version, state FROM ' . $quote(LegacyToOrm::LEDGER) . ' WHERE version=?', [ExactDiscriminators20261010::VERSION]);
        if ($this->receipt === false) {
            throw new LogicException('Capture a real completed exact-subject receipt; never synthesize one.');
        }
        $catalog = $platform instanceof AbstractMySQLPlatform ? BooleanDomainSchema::catalog($connection) : null;
        foreach (ExactDiscriminators20261010::definitions()['tables'] as $table => $definition) {
            $comment = (string)$connection->createSchemaManager()->introspectTable($table)->getColumn('items_id')->getComment();
            if ($platform instanceof AbstractMySQLPlatform) {
                $projection = $connection->fetchOne('SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'items_id']);
                $check = $catalog['checks'][$table][$definition['constraint']] ?? null;
                if (!is_string($projection) || $projection === '' || $check === null || $check['enforced'] !== 'YES') {
                    throw new LogicException('Complete enforced native subject schema required before fixture alteration.');
                }
                $this->original[$table] = ['projection' => $projection, 'check' => 'CHECK (' . $check['clause'] . ')', 'comment' => $comment];
            } else {
                $check = $connection->fetchAssociative('SELECT pg_get_constraintdef(oid) AS definition, convalidated FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND conname=? AND contype=?', [$quote($table), $definition['constraint'], 'c']);
                if (!is_array($check) || !in_array($check['convalidated'], [true, 1, '1', 't'], true)) {
                    throw new LogicException('Validated native subject CHECK required before fixture alteration.');
                }
                $nativeComment = $connection->fetchOne('SELECT col_description(a.attrelid, a.attnum) FROM pg_catalog.pg_attribute a WHERE a.attrelid=to_regclass(?) AND a.attname=? AND NOT a.attisdropped', [$quote($table), 'items_id']);
                $this->original[$table] = ['check' => $check['definition'], 'comment' => $nativeComment];
            }
        }
    }

    /** Current valid rows survive old collation behavior; deliberately bad rows are owned later. */
    public function detach(): void
    {
        $this->detached = true; // Partial setup failures must still restore all captured definitions.
        $this->connection->delete(LegacyToOrm::LEDGER, ['version' => ExactDiscriminators20261010::VERSION]);
        foreach (ExactDiscriminators20261010::definitions()['tables'] as $table => $definition) {
            $platform = $this->connection->getDatabasePlatform();
            if ($platform instanceof AbstractMySQLPlatform) {
                // Frozen branch scope; this fixture alone models the older
                // collation-sensitive generation, never current ORM metadata.
                $cases = [];
                foreach ($definition['branches'] as $kind => $branch) {
                    $cases[] = 'WHEN ' . $platform->quoteIdentifier('itemtype') . ' = ' . $platform->quoteStringLiteral($kind)
                        . ' THEN ' . $platform->quoteIdentifier($branch['column']);
                }
                $projection = 'CASE ' . implode(' ', $cases) . ' ELSE ' . ($definition['empty_value'] ?? 'NULL') . ' END';
                // TRUE intentionally admits owned corrupt rows on both engines.
                // This is a diagnostic fixture, not a claimed supported old schema.
                $this->replace($table, $projection, 'CHECK (TRUE)', $this->original[$table]['comment']);
            } else {
                $this->replace($table, null, 'CHECK (TRUE)', $this->original[$table]['comment']);
            }
        }
    }

    /** Restore original native definitions first; receipt only follows verified restoration. */
    public function restore(): void
    {
        if (!$this->detached) {
            return;
        }
        $errors = [];
        foreach ($this->original as $table => $definition) {
            try {
                $this->replace($table, $definition['projection'] ?? null, $definition['check'], $definition['comment']);
            } catch (Throwable $error) {
                $errors[] = $error;
            }
        }
        if ($errors) {
            // Do not restore a completion receipt over partially restored DDL.
            throw new RuntimeException('Historical subject fixture native restoration failed; completion receipt was not restored.', previous: $errors[0]);
        }
        $platform = $this->connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $catalog = $platform instanceof AbstractMySQLPlatform ? BooleanDomainSchema::catalog($this->connection) : null;
        foreach ($this->original as $table => $definition) {
            $name = ExactDiscriminators20261010::definitions()['tables'][$table]['constraint'];
            if ($platform instanceof AbstractMySQLPlatform) {
                $projection = $this->connection->fetchOne('SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'items_id']);
                $actual = ['projection' => $projection, 'check' => 'CHECK (' . ($catalog['checks'][$table][$name]['clause'] ?? '') . ')',
                    'comment' => (string)$this->connection->createSchemaManager()->introspectTable($table)->getColumn('items_id')->getComment()];
                if (($catalog['checks'][$table][$name]['enforced'] ?? null) !== 'YES') {
                    throw new RuntimeException('Historical fixture CHECK restoration did not enforce its captured definition.');
                }
            } else {
                $actual = ['check' => $this->connection->fetchOne('SELECT pg_get_constraintdef(oid) FROM pg_catalog.pg_constraint WHERE conrelid=to_regclass(?) AND conname=?', [$quote($table), $name]),
                    'comment' => $this->connection->fetchOne('SELECT col_description(a.attrelid, a.attnum) FROM pg_catalog.pg_attribute a WHERE a.attrelid=to_regclass(?) AND a.attname=? AND NOT a.attisdropped', [$quote($table), 'items_id'])];
            }
            if ($actual !== $definition) {
                throw new RuntimeException('Historical fixture native definitions did not restore exactly; completion receipt was not restored.');
            }
        }
        $this->connection->delete(LegacyToOrm::LEDGER, ['version' => ExactDiscriminators20261010::VERSION]);
        $this->connection->insert(LegacyToOrm::LEDGER, $this->receipt);
        $this->detached = false;
    }

    private function replace(string $table, ?string $projection, string $check, ?string $comment): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $name = ExactDiscriminators20261010::definitions()['tables'][$table]['constraint'];
        $sql = 'ALTER TABLE ' . $quote($table) . ' DROP ' . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $quote($name);
        if ($projection !== null) {
            $sql .= ', MODIFY COLUMN ' . $quote('items_id') . ' BIGINT GENERATED ALWAYS AS (' . $projection . ') STORED';
            if ($comment !== '') {
                $sql .= ' ' . $platform->getInlineColumnCommentSQL($comment);
            }
        }
        $sql .= ', ADD CONSTRAINT ' . $quote($name) . ' ' . $check . ($platform instanceof MySQLPlatform ? ' ENFORCED' : '');
        $this->connection->executeStatement($sql);
        if (!$platform instanceof AbstractMySQLPlatform) {
            $this->connection->executeStatement('COMMENT ON COLUMN ' . $quote($table) . '.' . $quote('items_id') . ' IS ' . ($comment === null ? 'NULL' : $platform->quoteStringLiteral($comment)));
        }
    }
}
