<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use itsmng\Database\BooleanCheckExpression;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\Migration\Ledger;

/** Frozen zero/one flag domains; integer storage, valid NULLs and defaults survive. */
final class BooleanDomains
{
    public const PHASE = '20261008_boolean_domains';

    public static function definitions(): array
    {
        static $definitions;
        $definitions ??= json_decode(file_get_contents(__DIR__ . '/history/20261008-boolean-domains.json'), true, flags: JSON_THROW_ON_ERROR)['tables'];
        return $definitions;
    }

    /** Full preflight precedes every DDL statement, including a resumed MySQL batch. */
    public function plan(Connection $connection, bool $preAdoption = false): array
    {
        if ((Ledger::state($connection, self::PHASE)['complete'] ?? false) === true) {
            return ['sql' => [], 'deferred_columns' => []];
        }
        return $this->inspect($connection, $preAdoption);
    }

    public function verify(Connection $connection): void
    {
        $plan = $this->inspect($connection, false);
        if ($plan['sql'] || $plan['deferred_columns']) {
            throw new \RuntimeException('Frozen boolean domains did not converge.');
        }
    }

    private function inspect(Connection $connection, bool $preAdoption): array
    {
        $states = $preAdoption ? Ledger::states($connection) : [];
        $pending = static fn (?string $version): bool => $preAdoption && $version !== null
            && ($states[$version]['complete'] ?? false) !== true;
        $catalog = BooleanDomainSchema::catalog($connection);
        $platform = $connection->getDatabasePlatform();
        $quote = $platform->quoteIdentifier(...);
        $problems = $sql = $deferred = [];
        foreach (self::definitions() as $table => $flags) {
            $predicates = $add = [];
            foreach ($flags as $column => $definition) {
                $key = $table . '.' . $column;
                $actual = $catalog['columns'][$table][$column] ?? null;
                if ($actual === null) {
                    if ($pending($definition['creation_version'])) {
                        $deferred[] = $key;
                    } else {
                        $problems[] = 'Missing boolean column: ' . $key;
                    }
                    continue;
                }
                $integer = in_array($actual['data_type'], ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true);
                $nativeBoolean = !$catalog['mysql'] && $actual['data_type'] === 'boolean';
                if (!$nativeBoolean && (!$integer || (!$catalog['mysql'] && !$pending($definition['conversion_version'])))) {
                    $problems[] = 'Unsupported boolean storage: ' . $key . ' (' . $actual['data_type'] . ')';
                    continue;
                }
                if (!$pending($definition['nullability_version']) && ($actual['is_nullable'] === 'YES') !== $definition['nullable']) {
                    $problems[] = 'Changed boolean nullability: ' . $key;
                }
                if ($integer && !self::validIntegerDefault($actual['column_default'])) {
                    $problems[] = 'Invalid boolean default: ' . $key . ' (' . $actual['column_default'] . ')';
                }
                $field = $quote($column);
                $invalid = !$definition['nullable'] ? $field . ' IS NULL' : '';
                if ($integer) {
                    $invalid .= ($invalid === '' ? '' : ' OR ') . '(' . $field . ' IS NOT NULL AND ' . $field . ' NOT IN (0, 1))';
                }
                if ($invalid !== '') {
                    $predicates[$column] = $invalid;
                }
                if (!$catalog['mysql']) {
                    continue;
                }
                $name = $definition['check'];
                $check = $catalog['checks'][$table][$name] ?? null;
                if ($check === null) {
                    $expression = $field . ($definition['nullable'] ? ' IS NULL OR ' : ' IS NOT NULL AND ') . $field . ' IN (0, 1)';
                    $add[] = 'ADD CONSTRAINT ' . $quote($name) . ' CHECK (' . $expression . ')'
                        . ($platform instanceof MySQLPlatform ? ' ENFORCED' : '');
                } elseif (!BooleanCheckExpression::matches($check['clause'], $column, $definition['nullable'], $catalog['ansi_quotes'])) {
                    $problems[] = 'Conflicting boolean CHECK: ' . $table . '.' . $name;
                } elseif ($check['enforced'] === 'NO' && $platform instanceof MySQLPlatform) {
                    $add[] = 'ALTER CHECK ' . $quote($name) . ' ENFORCED';
                } elseif ($check['enforced'] !== 'YES') {
                    $problems[] = 'Cannot establish boolean CHECK enforcement: ' . $table . '.' . $name;
                }
            }
            // Aggregate all flags of this table in one read, rather than one
            // full-table COUNT (or DBAL column introspection) per property.
            if ($predicates) {
                $counts = [];
                foreach (array_values($predicates) as $index => $predicate) {
                    $counts[] = 'SUM(CASE WHEN ' . $predicate . ' THEN 1 ELSE 0 END) AS b' . $index;
                }
                $counts = $connection->fetchAssociative('SELECT ' . implode(', ', $counts) . ' FROM ' . $quote($table));
                foreach (array_keys($predicates) as $index => $column) {
                    if (($count = (int)($counts['b' . $index] ?? 0)) === 0) {
                        continue;
                    }
                    $id = isset($catalog['columns'][$table]['id']) ? $quote('id') : $quote($column);
                    $samples = $connection->fetchAllAssociative('SELECT ' . $id . ', ' . $quote($column) . ' FROM ' . $quote($table)
                        . ' WHERE ' . $predicates[$column] . ' ORDER BY ' . $id . ' LIMIT 5');
                    $problems[] = 'Invalid boolean data: ' . $table . '.' . $column . ' (' . $count . ' rows); samples: ' . json_encode($samples, JSON_THROW_ON_ERROR);
                }
            }
            if ($add) {
                $sql[] = 'ALTER TABLE ' . $quote($table) . ' ' . implode(', ', $add);
            }
        }
        if ($problems) {
            throw new \RuntimeException("Boolean domain preflight failed before this migration's DDL:\n" . implode("\n", $problems));
        }
        return ['sql' => $sql, 'deferred_columns' => $deferred];
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        if ((Ledger::state($connection, self::PHASE)['complete'] ?? false) === true) {
            return;
        }
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        if ($mysql && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL boolean domain DDL must run outside an application transaction.');
        }
        $apply = function () use ($connection, $progress): void {
            $plan = $this->plan($connection);
            Ledger::save($connection, self::PHASE, ['complete' => false]);
            foreach ($plan['sql'] as $statement) {
                $connection->executeStatement($statement);
                $progress && $progress($statement);
            }
            // Re-read native definitions/data after committed DDL. Correct
            // partial batches need no rewrite on retry; wrong checks refuse.
            $remaining = $this->plan($connection);
            if ($remaining['sql'] || $remaining['deferred_columns']) {
                throw new \RuntimeException('Boolean domain migration did not converge; completion was not recorded.');
            }
            Ledger::save($connection, self::PHASE, ['complete' => true]);
        };
        $mysql ? $apply() : $connection->transactional($apply);
    }

    private static function validIntegerDefault(mixed $value): bool
    {
        if ($value === null || strtoupper((string)$value) === 'NULL') {
            return true;
        }
        // MariaDB quotes literal defaults in its native catalogue. Expressions
        // are not silently evaluated or cast into a flag during adoption.
        return preg_match('/^(?:[01]|\'[01]\')$/D', (string)$value) === 1;
    }
}
