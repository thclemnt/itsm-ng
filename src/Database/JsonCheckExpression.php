<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Only native JSON_VALID(quoted identifier) identifies a MariaDB JSON alias. */
final class JsonCheckExpression
{
    public static function column(string $clause, bool $ansiQuotes): ?string
    {
        if (strlen($clause) > 4096) {
            return null;
        }
        // Stock DBAL recognizes backtick-quoted catalogue identifiers. Add
        // only the ANSI quote form; bare arguments may be SQL constants or
        // no-parentheses built-ins rather than actual column references.
        $identifier = '`(?:[^`]|``)+`';
        if ($ansiQuotes) {
            $identifier .= '|"(?:[^"]|"")+"';
        }
        if (!preg_match('/^\s*((?:\(\s*)*)json_valid\s*\(\s*(' . $identifier . ')\s*\)\s*((?:\)\s*)*)$/iD', $clause, $match)
            || substr_count($match[1], '(') !== substr_count($match[3], ')')) {
            return null;
        }
        $column = $match[2];
        $delimiter = $column[0];
        return str_replace($delimiter . $delimiter, $delimiter, substr($column, 1, -1));
    }
}
