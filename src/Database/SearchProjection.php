<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/**
 * Transitional compiler for legacy search projection fragments. This is not a
 * general SQL translator: unsupported aggregate forms fail explicitly. New
 * search code should construct expressions through a provider-aware API.
 */
final class SearchProjection
{
    public function postgres(string $select, bool $grouped): string
    {
        if (!preg_match('/^(SELECT\s+(?:DISTINCT\s+)?)(.*)$/is', trim($select), $match)) {
            throw new \InvalidArgumentException('Expected a SELECT projection.');
        }
        $fields = [];
        foreach ($this->split($match[2], ',') as $field) {
            $field = $this->functions(trim($field));
            // MySQL permits ungrouped scalar fields in an aggregate projection.
            // Group each scalar deterministically on PostgreSQL as well.
            if ($grouped && !preg_match('/\b(?:STRING_AGG|COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $field)) {
                if (preg_match('/^(.*?)(\s+AS\s+.+)$/is', $field, $alias)) {
                    $field = 'MIN(' . $alias[1] . ')' . $alias[2];
                } else {
                    $field = 'MIN(' . $field . ')';
                }
            }
            $fields[] = $field;
        }
        return $match[1] . implode(', ', $fields);
    }

    private function functions(string $sql): string
    {
        $out = '';
        for ($i = 0, $length = strlen($sql); $i < $length; $i++) {
            if (in_array($sql[$i], ["'", '"', '`'], true)) {
                $end = $this->quotedEnd($sql, $i);
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif (preg_match('/\G([A-Z_][A-Z_0-9]*)\s*\(/Ai', $sql, $m, 0, $i)) {
                $open = $i + strlen($m[0]) - 1;
                $end = $this->parenthesisEnd($sql, $open);
                $body = substr($sql, $open + 1, $end - $open - 1);
                $name = strtoupper($m[1]);
                if ($name === 'GROUP_CONCAT') {
                    $out .= $this->aggregate($body);
                } elseif ($name === 'IFNULL') {
                    $args = $this->split($body, ',');
                    if (count($args) !== 2) {
                        throw new \InvalidArgumentException('Invalid IFNULL search expression.');
                    }
                    $out .= 'COALESCE(CAST(' . $this->functions($args[0]) . ' AS text), CAST(' . $this->functions($args[1]) . ' AS text))';
                } elseif ($name === 'CONCAT') {
                    // PostgreSQL CONCAT ignores NULL; MySQL propagates it.
                    $args = array_map(fn ($arg) => 'CAST(' . $this->functions($arg) . ' AS text)', $this->split($body, ','));
                    $out .= '(' . implode(' || ', $args) . ')';
                } else {
                    $out .= $m[1] . '(' . $this->functions($body) . ')';
                }
                $i = $end;
            } else {
                $out .= $sql[$i];
            }
        }
        return $out;
    }

    private function aggregate(string $body): string
    {
        $separator = $this->split($body, 'SEPARATOR');
        $body = trim($separator[0]);
        $delimiter = $separator[1] ?? "','";
        $order = $this->split($body, 'ORDER BY');
        $body = trim($order[0]);
        $distinct = preg_match('/^DISTINCT\s+/i', $body) === 1;
        $body = preg_replace('/^DISTINCT\s+/i', '', $body);
        $values = $this->split($body, ',');
        $expression = count($values) === 1 ? $this->functions($body) : $this->functions('CONCAT(' . $body . ')');
        $suffix = '';
        if (isset($order[1])) {
            if ($distinct) {
                throw new \RuntimeException('Ordered DISTINCT search aggregates require a correlated subquery on PostgreSQL; this search projection has not been ported yet.');
            }
            $suffix = ' ORDER BY ' . $this->functions($order[1]);
        }
        return 'STRING_AGG(' . ($distinct ? 'DISTINCT ' : '') . 'CAST(' . $expression . ' AS text), ' . trim($delimiter) . $suffix . ')';
    }

    private function split(string $sql, string $separator): array
    {
        $parts = [];
        $start = 0;
        for ($i = 0, $length = strlen($sql); $i < $length; $i++) {
            if (in_array($sql[$i], ["'", '"', '`'], true)) {
                $i = $this->quotedEnd($sql, $i);
            } elseif ($sql[$i] === '(') {
                $i = $this->parenthesisEnd($sql, $i);
            } elseif (strncasecmp(substr($sql, $i), $separator, strlen($separator)) === 0 && ($separator === ',' || (($i === 0 || !ctype_alnum($sql[$i - 1])) && !ctype_alnum($sql[$i + strlen($separator)] ?? ' ')))) {
                $parts[] = substr($sql, $start, $i - $start);
                $i += strlen($separator) - 1;
                $start = $i + 1;
            }
        }
        $parts[] = substr($sql, $start);
        return $parts;
    }

    private function quotedEnd(string $sql, int $start): int
    {
        $quote = $sql[$start];
        for ($i = $start + 1, $length = strlen($sql); $i < $length; $i++) {
            if ($quote === "'" && $sql[$i] === '\\') {
                $i++;
            } elseif ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i++;
                } else {
                    return $i;
                }
            }
        }
        throw new \InvalidArgumentException('Unterminated search literal.');
    }

    private function parenthesisEnd(string $sql, int $start): int
    {
        $depth = 1;
        for ($i = $start + 1, $length = strlen($sql); $i < $length; $i++) {
            if (in_array($sql[$i], ["'", '"', '`'], true)) {
                $i = $this->quotedEnd($sql, $i);
            } elseif ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')' && --$depth === 0) {
                return $i;
            }
        }
        throw new \InvalidArgumentException('Unbalanced search expression.');
    }
}
