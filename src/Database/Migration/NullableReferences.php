<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** Audited nullable-reference migration; run with application writers stopped. */
final class NullableReferences
{
    public function __construct(private array $relations, private string $label, private int $emptySelection = 0)
    {
        if (!in_array($emptySelection, [0, -1], true)) {
            throw new \InvalidArgumentException('Unsupported empty reference policy');
        }
    }

    public function plan(Connection $connection): array
    {
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $sql = [];
        $counts = [];
        $empty = $this->emptySelection === -1 ? '< 0' : '= 0';
        $present = $this->emptySelection === -1 ? '>= 0' : '<> 0';
        foreach ($this->relations as $table => $relations) {
            $before = $manager->introspectTable($table);
            $after = clone $before;
            foreach ($relations as $column => $target) {
                if ($connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($target) . ' WHERE id = ' . $this->emptySelection)) {
                    throw new \RuntimeException('Empty selection is a real ' . $this->label . ' identifier: ' . $target);
                }
                $orphans = $connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($table) . ' c LEFT JOIN ' . $quote($target) . ' p ON c.' . $quote($column) . ' = p.id WHERE c.' . $quote($column) . ' IS NOT NULL AND c.' . $quote($column) . ' ' . $present . ' AND p.id IS NULL');
                if ($orphans) {
                    throw new \RuntimeException('Nonzero orphaned ' . $this->label . ' references: ' . $table . '.' . $column . ' (' . $orphans . ')');
                }
                $after->getColumn($column)->setNotnull(false)->setDefault(null);
                $count = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $quote($table) . ' WHERE ' . $quote($column) . ' ' . $empty);
                if ($count) {
                    $counts[$table . '.' . $column] = $count;
                }
            }
            $diff = $manager->createComparator()->compareTables($before, $after);
            array_push($sql, ...$platform->getAlterTableSQL($diff));
        }
        return ['sql' => $sql, 'counts' => $counts];
    }

    public function apply(Connection $connection): array
    {
        // Audit every reference before any DDL. MySQL DDL implicitly commits;
        // retries use the current schema, then normalize data in a transaction.
        $plan = $this->plan($connection);
        if ($plan['sql'] && $connection->isTransactionActive() && !$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new \RuntimeException('MySQL ' . $this->label . ' DDL must run outside an application transaction.');
        }
        $empty = $this->emptySelection === -1 ? '< 0' : '= 0';
        $apply = static function () use ($connection, $plan, $empty): array {
            foreach ($plan['sql'] as $sql) {
                $connection->executeStatement($sql);
            }
            return $connection->transactional(static function () use ($connection, $plan, $empty): array {
                $counts = [];
                foreach ($plan['counts'] as $reference => $count) {
                    [$table, $column] = explode('.', $reference, 2);
                    $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
                    $counts[$reference] = $connection->executeStatement('UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = NULL WHERE ' . $quote($column) . ' ' . $empty);
                }
                return $counts;
            });
        };
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? $connection->transactional($apply) : $apply();
    }
}
