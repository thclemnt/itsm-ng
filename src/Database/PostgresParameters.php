<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Lexical parameter adaptation; SQL expressions and literal values are not inferred. */
final class PostgresParameters
{
    /** libpq $n positions become PDO positions, including repeated references. */
    public static function bind(string $sql, array $values): array
    {
        if ($values === []) {
            return [$sql, []];
        }
        $values = array_values($values);
        $bound = $used = [];
        $output = '';
        foreach (self::tokens($sql) as [$kind, $text]) {
            if ($kind === 'parameter') {
                $position = (int)substr($text, 1);
                if ($position < 1 || !array_key_exists($position - 1, $values)) {
                    throw new \InvalidArgumentException('PostgreSQL parameter position has no supplied value.');
                }
                $bound[] = $values[$position - 1];
                $used[$position] = true;
                $output .= '?';
            } elseif ($kind === 'question') {
                // In the numbered API an SQL ? is an operator, not a parameter.
                $output .= '??';
            } else {
                $output .= $text;
            }
        }
        if (count($used) !== count($values)) {
            throw new \InvalidArgumentException('PostgreSQL parameter values must have corresponding SQL positions.');
        }
        return [$output, $bound];
    }

    /** PostgreSQL lexical regions stay opaque to PDO's less capable parser. */
    public static function prepare(string $sql): string
    {
        $output = '';
        foreach (self::tokens($sql) as [$kind, $text]) {
            if ($kind === 'dollar') {
                $output .= self::literal($text);
            } elseif ($kind === 'comment') {
                // PostgreSQL nests comments; PDO stops at the first */. Keep
                // the outer comment, its body and line positions, but render
                // inner delimiters as whitespace within that same comment.
                $output .= '/*' . str_replace(['/*', '*/'], ['  ', '  '], substr($text, 2, -2)) . '*/';
            } else {
                $output .= $text;
            }
        }
        return $output;
    }

    private static function literal(string $quoted): string
    {
        $delimiterEnd = strpos($quoted, '$', 1);
        $delimiterLength = $delimiterEnd + 1;
        $body = substr($quoted, $delimiterLength, -$delimiterLength);
        return "E'" . str_replace(['\\', "'"], ['\\\\', "''"], $body) . "'";
    }

    /** Tokens preserve all bytes outside explicitly adapted parameter/literal delimiters. */
    private static function tokens(string $sql): \Generator
    {
        $length = strlen($sql);
        for ($offset = 0; $offset < $length;) {
            $start = $offset;
            $character = $sql[$offset];
            $kind = 'other';
            if ($character === "'" || $character === '"') {
                $delimiter = $character;
                $escaped = $delimiter === "'" && $offset > 0 && in_array($sql[$offset - 1], ['e', 'E'], true)
                    && ($offset < 2 || !preg_match('/[a-zA-Z0-9_$\x80-\xff]/', $sql[$offset - 2]));
                $offset++;
                $closed = false;
                while ($offset < $length) {
                    if ($escaped && $sql[$offset] === '\\') {
                        $offset += 2;
                    } elseif ($sql[$offset] === $delimiter) {
                        $offset++;
                        if (($sql[$offset] ?? '') === $delimiter) {
                            $offset++;
                        } else {
                            $closed = true;
                            break;
                        }
                    } else {
                        $offset++;
                    }
                }
                if (!$closed) {
                    throw new \InvalidArgumentException('Unterminated PostgreSQL literal or identifier.');
                }
            } elseif (substr($sql, $offset, 2) === '--') {
                $offset += 2;
                while ($offset < $length && !in_array($sql[$offset], ["\r", "\n"], true)) {
                    $offset++;
                }
            } elseif (substr($sql, $offset, 2) === '/*') {
                $kind = 'comment';
                $depth = 1;
                $offset += 2;
                while ($offset < $length && $depth > 0) {
                    if (substr($sql, $offset, 2) === '/*') {
                        $depth++;
                        $offset += 2;
                    } elseif (substr($sql, $offset, 2) === '*/') {
                        $depth--;
                        $offset += 2;
                    } else {
                        $offset++;
                    }
                }
                if ($depth !== 0) {
                    throw new \InvalidArgumentException('Unterminated PostgreSQL comment.');
                }
            } elseif ($character === '$' && ($offset === 0 || !preg_match('/[a-zA-Z0-9_$\x80-\xff]/', $sql[$offset - 1]))) {
                if (preg_match('/\G\$(?:[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)?\$/', $sql, $match, 0, $offset)) {
                    $delimiter = $match[0];
                    $end = strpos($sql, $delimiter, $offset + strlen($delimiter));
                    if ($end === false) {
                        throw new \InvalidArgumentException('Unterminated PostgreSQL dollar-quoted literal.');
                    }
                    $kind = 'dollar';
                    $offset = $end + strlen($delimiter);
                } elseif (preg_match('/\G\$[0-9]+/', $sql, $match, 0, $offset)) {
                    $kind = 'parameter';
                    $offset += strlen($match[0]);
                } else {
                    $offset++;
                }
            } else {
                $kind = $character === '?' ? 'question' : 'other';
                $offset++;
            }
            yield [$kind, substr($sql, $start, $offset - $start)];
        }
    }
}
