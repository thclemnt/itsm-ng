<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\MySQLSchemaManager;

/** Preserve DBAL inspection while recognizing MariaDB's native JSON alias under ANSI_QUOTES. */
final class MariaDBSchemaManager extends MySQLSchemaManager
{
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
