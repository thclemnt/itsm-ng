<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use InvalidArgumentException;
use itsmng\Database\Repository\RecordWriter;
use QueryExpression;
use QueryParam;

/** Persistence adapter beneath CommonDBTM's validation, hooks and history. */
final class MappedStorage
{
    public function __construct(private DBAdapter $db)
    {
    }

    public static function supports(string $table): bool
    {
        return isset(EntityRegistry::tables()[$table]);
    }

    public function insert(string $table, array $values): int
    {
        $em = Orm::create($this->db);
        try {
            return (new RecordWriter($em))->insert($table, ReferenceValues::normalizeLegacy($table, self::values($values)));
        } finally {
            $em->clear();
        }
    }

    /** @return string[] Columns changed by this unit of work. */
    public function update(string $table, int $id, array $values): array
    {
        $em = Orm::create($this->db);
        try {
            $changed = (new RecordWriter($em))->update($table, $id, ReferenceValues::normalizeLegacy($table, self::values($values)));
            return EntityConfigurationReferences::legacyChanges($table, $changed);
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
            if ($value instanceof QueryExpression || $value instanceof QueryParam) {
                throw new InvalidArgumentException('Mapped persistence requires values, not SQL expressions.');
            }
            // Decode CommonDBTM pre-escaping once before binding typed parameters.
            $value = LegacyValues::decode($value);
        }
        return $values;
    }

}
