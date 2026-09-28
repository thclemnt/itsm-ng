<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\RecordRepository;

/** Scoped ORM reads beneath the legacy model's row and lifecycle interfaces. */
final class MappedReads
{
    public static function matching(\DBAdapter $database, string $table, array $criteria = [], array|string $order = [], ?int $limit = null, int $offset = 0): array
    {
        if (!isset(EntityRegistry::TABLES[$table])) {
            throw new UnsupportedCriteria('Unmapped table requires a registered entity.');
        }
        $em = Orm::create($database);
        try {
            return (new RecordRepository($em))->matching($table, $criteria, $order, $limit, $offset);
        } finally {
            $em->clear();
        }
    }
    public static function countMatching(\DBAdapter $database, string $table, array $criteria): int
    {
        $em = Orm::create($database);
        try {
            return (new RecordRepository($em))->countMatching($table, $criteria);
        } finally {
            $em->clear();
        }
    }
}
