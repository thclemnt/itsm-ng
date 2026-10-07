<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use itsmng\Database\Repository\RecordRepository;

/** Scoped ORM reads beneath the legacy model's row and lifecycle interfaces. */
final class MappedReads
{
    public static function matching(DBAdapter $database, string $table, array $criteria = [], array|string $order = [], ?int $limit = null, int $offset = 0): array
    {
        if (!isset(EntityRegistry::tables()[$table])) {
            throw new UnsupportedCriteria('Unmapped table requires a registered entity.');
        }
        $operation = new RecordReadOperation($database->getDoctrineConnection());
        try {
            $rows = $operation->matching($table, $criteria, $order, $limit, $offset);
            return array_map(static fn (array $row): array => ReferenceValues::legacyRow($table, $row), $rows);
        } finally {
            $operation->close();
        }
    }

    public static function countMatching(DBAdapter $database, string $table, array $criteria): int
    {
        if (!isset(EntityRegistry::tables()[$table])) {
            throw new UnsupportedCriteria('Unmapped table requires a registered entity.');
        }
        $operation = new RecordReadOperation($database->getDoctrineConnection());
        try {
            return $operation->countMatching($table, $criteria);
        } finally {
            $operation->close();
        }
    }

    public static function identifiers(DBAdapter $database, string $table, string $column, array $criteria, array|string $order = []): array
    {
        if (!isset(EntityRegistry::tables()[$table])) {
            throw new UnsupportedCriteria('Unmapped table requires a registered entity.');
        }
        $em = Orm::create($database);
        try {
            return (new RecordRepository($em))->identifiers($table, $column, $criteria, $order);
        } finally {
            $em->clear();
        }
    }
}
