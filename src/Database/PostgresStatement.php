<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Compatibility facade for legacy mysqli bind_param callers. Values remain bound. */
final class PostgresStatement
{
    public string $error = '';
    public int $affected_rows = 0;
    private array $values = [];
    private string $types = '';
    private mixed $result = false;

    public function __construct(private \DBpgsql $db, private string $sql)
    {
    }

    public function bind_param(string $types, mixed &...$values): bool
    {
        if (strlen($types) !== count($values) || preg_match('/[^idsb]/', $types)) {
            throw new \InvalidArgumentException('Parameter types and values must match.');
        }
        $this->values = &$values;
        $this->types = $types;
        return true;
    }

    public function execute(?array $values = null): bool
    {
        $values ??= $this->values;
        foreach ($values as $i => $value) {
            if ($value !== null) {
                $values[$i] = match ($this->types[$i] ?? 's') {
                    'i' => (int)$value,
                    'd' => (float)$value,
                    default => (string)$value,
                };
            }
        }
        if ($this->result) {
            $this->db->freeResult($this->result);
        }
        $this->result = $this->db->queryParams($this->sql, $values);
        $this->error = $this->db->error();
        $this->affected_rows = $this->db->affectedRows();
        return $this->result !== false;
    }

    public function get_result(): mixed
    {
        return $this->result;
    }

    public function close(): bool
    {
        if ($this->result) {
            $this->db->freeResult($this->result);
            $this->result = false;
        }
        return true;
    }
}
