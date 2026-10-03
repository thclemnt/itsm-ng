<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Compatibility facade for legacy mysqli bind_param callers. Values remain bound. */
final class LegacyStatement
{
    public string $error = '';
    public int $affected_rows = 0;
    private array $values = [];
    private string $types = '';
    private mixed $result = false;
    private ?\Doctrine\DBAL\Statement $statement;
    private readonly \Doctrine\DBAL\Connection $owner;

    public function __construct(private \DBmysql $db, private string $sql)
    {
        $this->owner = $db->getDoctrineConnection();
        $this->statement = $this->owner->prepare($sql);
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
        if ($this->db->getDoctrineConnection() !== $this->owner) {
            throw new \LogicException('Prepared statement belongs to a different supplied DBAL owner.');
        }
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
        if ($this->result instanceof LegacyResult) {
            $this->db->freeResult($this->result);
        }
        if ($this->statement === null) {
            throw new \LogicException('Prepared statement is closed.');
        }
        foreach ($values as $index => $value) {
            $type = match ($this->types[$index] ?? 's') {
                'i' => \Doctrine\DBAL\ParameterType::INTEGER,
                'd' => \Doctrine\DBAL\Types\Types::FLOAT,
                'b' => is_resource($value) ? \Doctrine\DBAL\ParameterType::LARGE_OBJECT : \Doctrine\DBAL\ParameterType::BINARY,
                default => \Doctrine\DBAL\ParameterType::STRING,
            };
            $this->statement->bindValue($index + 1, $value, $value === null ? \Doctrine\DBAL\ParameterType::NULL : $type);
        }
        $this->result = $this->db->executePrepared($this->statement, $this->sql);
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
        $this->statement = null;
        return true;
    }
}
