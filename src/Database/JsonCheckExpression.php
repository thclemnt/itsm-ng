<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Only a native JSON_VALID(identifier) declaration identifies a MariaDB JSON alias. */
final class JsonCheckExpression
{
    public static function column(string $clause, bool $ansiQuotes): ?string
    {
        if (strlen($clause) > 4096) {
            return null;
        }
        $identifier = '`(?:[^`]|``)+`|[a-zA-Z_][a-zA-Z_0-9$]*';
        if ($ansiQuotes) {
            $identifier .= '|"(?:[^"]|"")+"';
        }
        if (!preg_match('/^\s*((?:\(\s*)*)json_valid\s*\(\s*(' . $identifier . ')\s*\)\s*((?:\)\s*)*)$/iD', $clause, $match)
            || substr_count($match[1], '(') !== substr_count($match[3], ')')) {
            return null;
        }
        $column = $match[2];
        if ($column[0] === '`' || $column[0] === '"') {
            $delimiter = $column[0];
            return str_replace($delimiter . $delimiter, $delimiter, substr($column, 1, -1));
        }
        return $column;
    }
}
