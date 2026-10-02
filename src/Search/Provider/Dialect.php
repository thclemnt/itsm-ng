<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Search\Provider;

/** SQL differences are selected while building expressions, never by rewriting SQL. */
final class Dialect
{
    public function __construct(private \DBAdapter $db)
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
        if ($this->postgres() && \itsmng\Database\EntityRegistry::isBoolean($table, $column)) {
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
    /** @param array<string, string> $order SQL expression => ASC/DESC */
    public function aggregate(string $expression, bool $distinct = true, array $order = [], string $separator = \Search::LONGSEP): string
    {
        $sort = [];
        foreach ($order as $key => $direction) {
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                throw new \InvalidArgumentException('Invalid aggregate ordering');
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
