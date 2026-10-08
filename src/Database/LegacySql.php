<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use InvalidArgumentException;

/**
 * Lexical bridge for the application's pre-escaped SQL API.
 * Only delimiters and MySQL string escapes are converted here. Values, comments,
 * and identifiers must never be rewritten by SQL keyword substitutions.
 * New code should bind values using queryParams() instead.
 */
final class LegacySql
{
    public static function postgres(string $sql, bool $parameters = false): string
    {
        $out = '';
        $parameter = 0;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === '$' && ($i === 0 || !preg_match('/[a-zA-Z0-9_$\x80-\xff]/', $sql[$i - 1]))
                && preg_match('/\G\$(?:[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)?\$/', $sql, $match, 0, $i)) {
                $end = strpos($sql, $match[0], $i + strlen($match[0]));
                if ($end === false) {
                    throw new InvalidArgumentException('Unterminated SQL dollar-quoted literal.');
                }
                $out .= substr($sql, $i, $end + strlen($match[0]) - $i);
                $i = $end + strlen($match[0]) - 1;
            } elseif ($char === "'" || $char === '`' || $char === '"') {
                $delimiter = $char;
                $value = '';
                $closed = false;
                while (++$i < $length) {
                    $char = $sql[$i];
                    if ($char === $delimiter) {
                        if (($sql[$i + 1] ?? '') === $delimiter) {
                            $value .= $delimiter;
                            $i++;
                            continue;
                        }
                        $closed = true;
                        break;
                    }
                    if ($delimiter === "'" && $char === '\\' && $i + 1 < $length) {
                        $escaped = $sql[++$i];
                        $value .= match ($escaped) {
                            'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08",
                            '0' => "\0", 'Z' => "\x1a", '%', '_' => '\\' . $escaped,
                            default => $escaped,
                        };
                    } else {
                        $value .= $char;
                    }
                }
                if (!$closed) {
                    throw new InvalidArgumentException('Unterminated SQL literal or identifier.');
                }
                if (str_contains($value, "\0")) {
                    throw new InvalidArgumentException('PostgreSQL text cannot contain NUL bytes.');
                }
                $quote = $delimiter === "'" ? "'" : '"';
                $out .= $quote . str_replace($quote, $quote . $quote, $value) . $quote;
            } elseif ($char === '#' || substr($sql, $i, 2) === '--') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $out .= '-- ' . substr($sql, $i + ($char === '#' ? 1 : 2), $end - $i - ($char === '#' ? 1 : 2)) . "\n";
                $i = $end;
            } elseif (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new InvalidArgumentException('Unterminated SQL comment.');
                }
                $out .= substr($sql, $i, $end + 2 - $i);
                $i = $end + 1;
            } elseif ($char === '?' && $parameters) {
                $out .= '$' . ++$parameter;
            } else {
                $out .= $char;
            }
        }
        return $out;
    }
}
