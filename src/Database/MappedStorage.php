<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\RecordWriter;

/** Persistence adapter beneath CommonDBTM's validation, hooks and history. */
final class MappedStorage
{
    public const TABLES = EntityRegistry::TABLES;

    public function __construct(private \DBAdapter $db)
    {
    }

    public static function supports(string $table): bool
    {
        return isset(self::TABLES[$table]);
    }

    public function insert(string $table, array $values): int
    {
        $em = Orm::create($this->db);
        try {
            return (new RecordWriter($em))->insert($table, self::values($values));
        } finally {
            $em->clear();
        }
    }

    /** @return string[] Columns changed by this unit of work. */
    public function update(string $table, int $id, array $values): array
    {
        $em = Orm::create($this->db);
        try {
            return (new RecordWriter($em))->update($table, $id, self::values($values));
        } finally {
            $em->clear();
        }
    }

    public function delete(string $table, int $id): bool
    {
        $em = Orm::create($this->db);
        try {
            (new RecordWriter($em))->delete($table, $id);
            return true;
        } finally {
            $em->clear();
        }
    }

    private static function values(array $values): array
    {
        foreach ($values as &$value) {
            if ($value instanceof \QueryExpression || $value instanceof \QueryParam) {
                throw new \InvalidArgumentException('Mapped persistence requires values, not SQL expressions.');
            }
            // Decode CommonDBTM pre-escaping once before binding typed parameters.
            $value = self::decode($value);
        }
        return $values;
    }

    private static function decode(mixed $value): mixed
    {
        if ($value === 'NULL' || $value === 'null') {
            return null;
        }
        if (!is_string($value)) {
            return $value;
        }
        return preg_replace_callback('/\\\\(.)/s', static fn ($m) => match ($m[1]) {
            'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", '0' => "\0", 'Z' => "\x1a",
            '%', '_' => $m[0], default => $m[1],
        }, $value);
    }
}
