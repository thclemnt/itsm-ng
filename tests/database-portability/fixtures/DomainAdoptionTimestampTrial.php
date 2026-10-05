<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Migration\LegacyToOrm;

/** Rollback-only writes to actual frozen target columns; no version-based capability guess. */
final class DomainAdoptionTimestampTrial
{
    public function __construct(private Connection $connection)
    {
        if ($connection->isTransactionActive() || !str_starts_with($connection->getDatabase() ?? '', 'itsm_port_')) {
            throw new LogicException('Idle disposable historical Domain fixture required.');
        }
    }

    /** Exact duplicate-preserving source/core bags and native schema, including absent raw ledger. */
    public function snapshot(): array
    {
        $manager = $this->connection->createSchemaManager();
        $tables = $manager->listTableNames();
        sort($tables, SORT_STRING);
        verify(!in_array(LegacyToOrm::LEDGER, $tables, true), 'Timestamp trial must not bootstrap the adoption ledger');
        $bags = [];
        foreach ($tables as $table) {
            $rows = array_map('serialize', $this->connection->fetchAllAssociative('SELECT * FROM ' . $this->connection->quoteIdentifier($table)));
            sort($rows, SORT_STRING);
            $bags[$table] = $rows;
        }
        $postgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $schema = $postgres ? $this->connection->fetchOne('SELECT current_schema()') : $this->connection->getDatabase();
        $native = [];
        foreach (['columns' => 'table_schema', 'table_constraints' => 'constraint_schema', 'key_column_usage' => 'constraint_schema',
            'referential_constraints' => 'constraint_schema', 'check_constraints' => 'constraint_schema'] as $catalog => $schemaColumn) {
            $rows = array_map('serialize', $this->connection->fetchAllAssociative('SELECT * FROM information_schema.' . $catalog . ' WHERE ' . $schemaColumn . ' = ?', [$schema]));
            sort($rows, SORT_STRING);
            $native[$catalog] = $rows;
        }
        $indexSql = $postgres ? 'SELECT * FROM pg_indexes WHERE schemaname = ?'
            : 'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART, NULLABLE, INDEX_TYPE, INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?';
        $native['indexes'] = array_map('serialize', $this->connection->fetchAllAssociative($indexSql, [$schema]));
        sort($native['indexes'], SORT_STRING);
        $tableSql = $postgres ? 'SELECT table_name, table_type FROM information_schema.tables WHERE table_schema = ?'
            : 'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, ROW_FORMAT, AUTO_INCREMENT, TABLE_COLLATION, CREATE_OPTIONS, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?';
        $native['tables'] = array_map('serialize', $this->connection->fetchAllAssociative($tableSql, [$schema]));
        sort($native['tables'], SORT_STRING);
        return ['rows' => $bags, 'native' => $native];
    }

    /** Narrow engines reject; a successful statement must retain exact values rather than coerce. */
    public function supports(string $date): bool
    {
        $values = ['date_creation' => $date . ' 00:00:00', 'date_expiration' => '2027-12-31 00:00:00', 'date_mod' => '2026-10-01 12:34:56'];
        $this->connection->beginTransaction();
        try {
            try {
                $this->connection->update('glpi_domains', $values, ['id' => 9005]);
            } catch (DriverException $error) {
                // Only a native date-range rejection establishes a narrower
                // target. Permissions, connectivity and unrelated SQL errors fail.
                if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
                    || !in_array($error->getSQLState(), ['22007', '22008'], true)
                    || !in_array((int)($error->getPrevious()?->getCode()), [1264, 1292], true)) {
                    throw $error;
                }
                return false;
            }
            $this->verifyStored($values, 'Native date capability');
            $this->verifyStoredNulls();
            return true;
        } finally {
            $this->connection->rollBack();
        }
    }

    /** Trial the actual plan's dates on an existing row, without consuming identity allocators. */
    public function verifyPlannedDates(array $plan, string $futureDate): void
    {
        $expected = [
            100010 => ['date_creation' => $futureDate . ' 00:00:00', 'date_expiration' => '2027-12-31 00:00:00', 'date_mod' => '2026-10-01 12:34:56'],
            100011 => ['date_creation' => '2026-01-01 00:00:00', 'date_expiration' => '2027-12-31 00:00:00', 'date_mod' => '2026-10-01 12:34:56'],
            100012 => ['date_creation' => null, 'date_expiration' => null, 'date_mod' => null],
        ];
        $actual = [];
        $this->connection->beginTransaction();
        try {
            foreach ($plan['records'] as $record) {
                if ($record['table'] !== 'glpi_domains') {
                    continue;
                }
                $values = array_intersect_key($record['values'], $expected[100010]);
                $id = $record['values']['id'];
                verify(isset($expected[$id]) && $values === $expected[$id], 'Accepted plan preserves exact DATE midnights, second precision and NULL: ' . $id);
                verify(!isset($actual[$id]), 'Accepted plan contains each original Domain exactly once');
                $actual[$id] = $values;
                $this->connection->update('glpi_domains', $values, ['id' => 9005]);
                $this->verifyStored($values, 'Accepted date plan');
            }
            ksort($actual);
            verify($actual === $expected, 'Accepted plan preserves all three source Domain date records');
        } finally {
            $this->connection->rollBack();
        }
    }

    private function verifyStored(array $values, string $context): void
    {
        // PostgreSQL TIMESTAMPTZ output includes the session offset. Reading
        // its local timestamp as text preserves fractional seconds too, so
        // neither timezone suffixes nor a truncated string hide lost precision.
        $sql = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'SELECT CAST(date_creation AS timestamp)::text AS date_creation, CAST(date_expiration AS timestamp)::text AS date_expiration, CAST(date_mod AS timestamp)::text AS date_mod FROM glpi_domains WHERE id = 9005'
            : 'SELECT date_creation, date_expiration, date_mod FROM glpi_domains WHERE id = 9005';
        verify($this->connection->fetchAssociative($sql) === $values,
            $context . ' stores exact calendar midnight, second precision and native NULL');
    }

    private function verifyStoredNulls(): void
    {
        $values = ['date_creation' => null, 'date_expiration' => null, 'date_mod' => null];
        $this->connection->update('glpi_domains', $values, ['id' => 9005]);
        $this->verifyStored($values, 'Native nullable date capability');
    }
}
