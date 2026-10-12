<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\MySQLSchemaManager;

/** Preserve DBAL inspection while recognizing MariaDB's native JSON alias under ANSI_QUOTES. */
final class MariaDBSchemaManager extends MySQLSchemaManager
{
    protected function selectTableColumns(string $databaseName, ?string $tableName = null): Result
    {
        // Keep DBAL's native fields and schema filters. JSON aliases are
        // classified in one scoped pass below, without a correlated CHECK scan.
        $conditions = ['c.TABLE_SCHEMA = ?', 't.TABLE_SCHEMA = ?'];
        $params     = [$databaseName, $databaseName];

        if ($tableName !== null) {
            $conditions[] = 't.TABLE_NAME = ?';
            $params[]     = $tableName;
        }

        $sql = sprintf(
            <<<'SQL'
SELECT
       c.TABLE_NAME,
       c.COLUMN_NAME        AS field,
       c.DATA_TYPE          AS type,
       c.COLUMN_TYPE,
       c.CHARACTER_MAXIMUM_LENGTH,
       c.CHARACTER_OCTET_LENGTH,
       c.NUMERIC_PRECISION,
       c.NUMERIC_SCALE,
       c.IS_NULLABLE        AS `null`,
       c.COLUMN_KEY         AS `key`,
       c.COLUMN_DEFAULT     AS `default`,
       c.EXTRA,
       c.COLUMN_COMMENT     AS comment,
       c.CHARACTER_SET_NAME AS characterset,
       c.COLLATION_NAME     AS collation
FROM information_schema.COLUMNS c
    INNER JOIN information_schema.TABLES t
        ON t.TABLE_NAME = c.TABLE_NAME
 WHERE %s
   AND t.TABLE_TYPE = 'BASE TABLE'
ORDER BY c.TABLE_NAME,
         c.ORDINAL_POSITION
SQL,
            implode(' AND ', $conditions),
        );

        return $this->connection->executeQuery($sql, $params);
    }

    protected function fetchTableColumns(string $databaseName, ?string $tableName = null): array
    {
        $rows = parent::fetchTableColumns($databaseName, $tableName);
        $longtext = [];
        foreach ($rows as $position => $row) {
            $column = array_change_key_case($row, CASE_LOWER);
            if (strtolower($column['column_type']) === 'longtext') {
                $longtext[$column['table_name']][strtolower($column['field'])] = $position;
                // Recompute only the native LONGTEXT aliases. Plain text and
                // constant/literal lookalike checks must remain native text.
                $rows[$position]['type'] = 'longtext';
            }
        }
        if (!$longtext) {
            return $rows;
        }
        $modes = array_map('strtoupper', array_map('trim', explode(',', (string)$this->connection->fetchOne('SELECT @@SESSION.sql_mode'))));
        $ansiQuotes = in_array('ANSI_QUOTES', $modes, true);
        $sql = 'SELECT TABLE_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME IN (?)';
        foreach ($this->connection->fetchAllAssociative($sql, [$databaseName, array_keys($longtext)], [1 => ArrayParameterType::STRING]) as $row) {
            $check = array_change_key_case($row, CASE_LOWER);
            $column = JsonCheckExpression::column($check['check_clause'], $ansiQuotes);
            if ($column !== null && isset($longtext[$check['table_name']][strtolower($column)])) {
                $rows[$longtext[$check['table_name']][strtolower($column)]]['type'] = 'json';
            }
        }
        return $rows;
    }
}
