<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBpgsql;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use itsmng\Database\Driver\Postgres\OwnedStatement;
use LogicException;

/** Compatibility facade for legacy mysqli bind_param callers. Values remain bound. */
final class PostgresStatement
{
    public string $error = '';
    public int $affected_rows = 0;
    private array $values = [];
    private string $types = '';
    private mixed $result = false;
    private ?Statement $statement;

    public function __construct(private DBpgsql $db, private string $sql)
    {
        $this->statement = $db->getDoctrineConnection()->prepareLegacyStatement($sql);
    }

    public function bind_param(string $types, mixed &...$values): bool
    {
        if (strlen($types) !== count($values) || preg_match('/[^idsb]/', $types)) {
            throw new InvalidArgumentException('Parameter types and values must match.');
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
                    'b' => $value,
                    default => (string)$value,
                };
            }
        }
        if ($this->statement === null) {
            throw new LogicException('Prepared statement is closed.');
        }
        $types = [];
        foreach ($values as $index => $value) {
            if ($value !== null && ($this->types[$index] ?? 's') !== 'b' && is_string($value) && str_contains($value, "\0")) {
                throw new InvalidArgumentException('PostgreSQL text parameters cannot contain NUL bytes.');
            }
            $types[$index] = match ($this->types[$index] ?? 's') {
                'i' => ParameterType::INTEGER,
                'b' => is_resource($value) ? ParameterType::LARGE_OBJECT : ParameterType::BINARY,
                default => ParameterType::STRING,
            };
        }
        $result = $this->db->executePrepared($this->statement, $this->sql, $values, $types);
        if ($this->result instanceof LegacyResult) {
            $this->db->freeResult($this->result);
        }
        $this->result = $result;
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
        if ($this->result instanceof LegacyResult) {
            $this->db->freeResult($this->result);
            $this->result = false;
        }
        if ($this->statement instanceof OwnedStatement) {
            $this->statement->close();
        }
        $this->statement = null;
        return true;
    }
}
