<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Search\Provider;

use DBAdapter;
use InvalidArgumentException;
use itsmng\Database\EntityRegistry;
use Search;

/** SQL differences are selected while building expressions, never by rewriting SQL. */
final class Dialect
{
    public function __construct(private DBAdapter $db)
    {
    }

    public function postgres(): bool
    {
        return $this->db->getProvider() === 'pgsql';
    }

    public function quote(string $name): string
    {
        return $this->db->quoteName($name);
    }

    public function column(string $table, string $column, ?string $alias = null): string
    {
        $sql = $this->quote(($alias ?? $table) . '.' . $column);
        if ($this->postgres() && EntityRegistry::isBoolean($table, $column)) {
            // The existing display encoding uses 0/1. Preserve NULL on outer joins.
            return 'CAST(' . $sql . ' AS integer)';
        }
        return $sql;
    }

    public function literal(string $value): string
    {
        return $this->db->quoteValue($this->db->escape($value));
    }

    public function text(string $expression): string
    {
        return $this->postgres() ? "CAST($expression AS text)" : "CAST($expression AS CHAR)";
    }

    public function coalesceText(string $expression, string $fallback): string
    {
        return 'COALESCE(' . $this->text($expression) . ', ' . $this->literal($fallback) . ')';
    }

    public function concat(string ...$expressions): string
    {
        return $this->postgres()
            ? '(' . implode(' || ', array_map($this->text(...), $expressions)) . ')'
            : 'CONCAT(' . implode(', ', $expressions) . ')';
    }

    public function compareIPv4(string $alias, string $operator, string $address): string
    {
        if (!in_array($operator, ['<', '<=', '>', '>='], true)) {
            throw new InvalidArgumentException('Invalid IP comparison');
        }
        // Preserve INET_ATON's one-to-four-part IPv4 input convention without
        // casting arbitrary stored text to PostgreSQL inet (IPv6 is not IPv4).
        $parts = explode('.', $address);
        $number = 0;
        $valid = count($parts) <= 4;
        foreach ($parts as $index => $part) {
            $last = $index === count($parts) - 1;
            $width = $last ? 5 - count($parts) : 1;
            if (!ctype_digit($part) || (float)$part >= 256 ** $width) {
                $valid = false;
                break;
            }
            $number = $number * (256 ** $width) + (int)$part;
        }
        $value = $valid ? (string)$number : 'NULL';
        $version = $this->quote($alias . '.version');
        $binary = $this->quote($alias . '.binary_3');
        return '(CASE WHEN ' . $version . ' = 4 THEN ' . $binary . ' END) ' . $operator . ' ' . $value;
    }

    /** @param array<string, string> $order SQL expression => ASC/DESC */
    public function aggregate(string $expression, bool $distinct = true, array $order = [], string $separator = Search::LONGSEP): string
    {
        $sort = [];
        foreach ($order as $key => $direction) {
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                throw new InvalidArgumentException('Invalid aggregate ordering');
            }
            // MySQL puts NULL first for ASC, PostgreSQL last. Make the policy explicit.
            $sort[] = $key . ' ' . $direction . ($this->postgres() ? ($direction === 'ASC' ? ' NULLS FIRST' : ' NULLS LAST') : '');
        }
        $ordering = $sort ? ' ORDER BY ' . implode(', ', $sort) : '';
        $delimiter = $this->literal($separator);
        if (!$this->postgres()) {
            return 'GROUP_CONCAT(' . ($distinct ? 'DISTINCT ' : '') . $expression . $ordering . ' SEPARATOR ' . $delimiter . ')';
        }
        $value = $this->text($expression);
        if (!$distinct || !$order) {
            return 'STRING_AGG(' . ($distinct ? 'DISTINCT ' : '') . $value . ', ' . $delimiter . $ordering . ')';
        }
        // PostgreSQL cannot ORDER BY a different key in STRING_AGG(DISTINCT).
        // Aggregate ordered values from the outer group, then retain the first
        // occurrence of each value. No record casts or lossy text sort keys.
        return '(SELECT STRING_AGG(v, ' . $delimiter . ' ORDER BY position) FROM ('
            . 'SELECT v, MIN(n) AS position FROM UNNEST(ARRAY_AGG(' . $value . $ordering . '))'
            . ' WITH ORDINALITY AS ordered_values(v, n) WHERE v IS NOT NULL GROUP BY v'
            . ') AS distinct_values)';
    }
}
