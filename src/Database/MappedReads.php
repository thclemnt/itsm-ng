<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Repository\RecordRepository;

/** Scoped ORM reads beneath the legacy model's row and lifecycle interfaces. */
final class MappedReads
{
    public static function matching(DBAdapter $database, string $table, array $criteria = [], array|string $order = [], ?int $limit = null, int $offset = 0): array
    {
        if (!isset(EntityRegistry::tables()[$table])) {
            throw new UnsupportedCriteria('Unmapped table requires a registered entity.');
        }
        $connection = $database->getDoctrineConnection();
        $result = Orm::withReadConnection($connection, static function (?EntityManager $manager) use ($connection, $table, $criteria, $order, $limit, $offset): array|UnsupportedCriteria {
            $operation = new RecordReadOperation($connection, $manager);
            try {
                $rows = $operation->matchingResult($table, $criteria, $order, $limit, $offset);
                if ($rows instanceof UnsupportedCriteria) {
                    return $rows;
                }
                return array_map(static fn (array $row): array => ReferenceValues::legacyRow($table, $row), $rows);
            } finally {
                $operation->close();
            }
        });
        if ($result instanceof UnsupportedCriteria) {
            // The completed value-only scope has cleared normally; keep find's public fallback.
            throw $result;
        }
        return $result;
    }

    public static function countMatching(DBAdapter $database, string $table, array $criteria): int
    {
        if (!isset(EntityRegistry::tables()[$table])) {
            throw new UnsupportedCriteria('Unmapped table requires a registered entity.');
        }
        $connection = $database->getDoctrineConnection();
        return Orm::withReadConnection($connection, static function (?EntityManager $manager) use ($connection, $table, $criteria): int {
            $operation = new RecordReadOperation($connection, $manager);
            try {
                return $operation->countMatching($table, $criteria);
            } finally {
                $operation->close();
            }
        });
    }

    public static function identifiers(DBAdapter $database, string $table, string $column, array $criteria, array|string $order = []): array
    {
        if (!isset(EntityRegistry::tables()[$table])) {
            throw new UnsupportedCriteria('Unmapped table requires a registered entity.');
        }
        $connection = $database->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($database, $connection);
        return Orm::withConnection($connection, static function (EntityManager $manager) use ($table, $column, $criteria, $order): array {
            return (new RecordRepository($manager))->identifiers($table, $column, $criteria, $order);
        });
    }
}
