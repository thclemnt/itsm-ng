<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

/** Idempotent data migration; existing nullable column definitions stay unchanged. */
final class NormalizeOptionalReferences
{
    public const VERSION = '20260928_optional_model_references';

    public function plan(Connection $connection): array
    {
        $counts = [];
        $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
        $manager = $connection->createSchemaManager();
        foreach (OptionalReferences::MODELS as $table => $relations) {
            $columns = $manager->listTableColumns($table);
            foreach ($relations as $column => $target) {
                if ($columns[$column]->getNotnull()) {
                    throw new \RuntimeException('Expected a nullable optional reference: ' . $table . '.' . $column);
                }
                if ($connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($target) . ' WHERE id = 0')) {
                    throw new \RuntimeException('Zero is a real model identifier; resolve it before normalization: ' . $target);
                }
                $orphans = $connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($table) . ' c LEFT JOIN ' . $quote($target) . ' p ON c.' . $quote($column) . ' = p.id WHERE c.' . $quote($column) . ' IS NOT NULL AND c.' . $quote($column) . ' <> 0 AND p.id IS NULL');
                if ($orphans) {
                    throw new \RuntimeException('Nonzero orphaned references require correction before normalization: ' . $table . '.' . $column . ' (' . $orphans . ')');
                }
                $count = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($table) . ' WHERE ' . $quote($column) . ' = 0');
                if ($count) {
                    $counts[$table . '.' . $column] = $count;
                }
            }
        }
        return $counts;
    }

    public function apply(Connection $connection): array
    {
        return $connection->transactional(function () use ($connection): array {
            $counts = $this->plan($connection);
            foreach ($counts as $reference => &$count) {
                [$table, $column] = explode('.', $reference, 2);
                $count = $connection->update($table, [$column => null], [$column => 0]);
            }
            return $counts;
        });
    }
}
