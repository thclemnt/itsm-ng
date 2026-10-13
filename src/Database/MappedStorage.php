<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\ORM\EntityManager;
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
        $connection = $this->db->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($this->db, $connection);
        return Orm::withConnection($connection, static function (EntityManager $manager) use ($table, $values): int {
            return (new RecordWriter($manager))->insert($table, ReferenceValues::normalizeLegacy($table, self::values($table, $values)));
        });
    }

    /** @return string[] Columns changed by this unit of work. */
    public function update(string $table, int $id, array $values): array
    {
        $connection = $this->db->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($this->db, $connection);
        return Orm::withConnection($connection, static function (EntityManager $manager) use ($table, $id, $values): array {
            $changed = (new RecordWriter($manager))->update($table, $id, ReferenceValues::normalizeLegacy($table, self::values($table, $values)));
            return EntityConfigurationReferences::legacyChanges($table, $changed);
        });
    }

    public function delete(string $table, int $id): bool
    {
        $connection = $this->db->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($this->db, $connection);
        return Orm::withConnection($connection, static function (EntityManager $manager) use ($table, $id): bool {
            (new RecordWriter($manager))->delete($table, $id);
            return true;
        });
    }

    private static function values(string $table, array $values): array
    {
        $types = array_diff_key(
            EntityRegistry::fieldTypes($table),
            array_fill_keys(EntityRegistry::readOnlyColumns($table), true)
        );
        foreach ($values as $column => &$value) {
            if ($value instanceof QueryExpression || $value instanceof QueryParam) {
                throw new InvalidArgumentException('Mapped persistence requires values, not SQL expressions.');
            }
            // Decode CommonDBTM pre-escaping once before binding typed parameters.
            $value = is_string($value) && LegacyValues::isTextType($types[$column] ?? null)
                ? LegacyValues::decodeString($value)
                : LegacyValues::decode($value);
        }
        return $values;
    }

}
