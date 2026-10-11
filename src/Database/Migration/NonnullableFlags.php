<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\BooleanCheckExpression;
use itsmng\Database\BooleanDomainSchema;
use itsmng\Database\OwnedMutationFrame;
use RuntimeException;

/** Frozen forward conversion of fourteen nonnullable zero/one application flags. */
final class NonnullableFlags implements ReleaseMigration
{
    public const VERSION = 'schema.nonnullable_flags.v1';

    // Historical scope/default facts. Later mappings cannot change this release.
    private const FLAGS = [
        'glpi_apiclients' => [
            'is_active' => ['default' => 0, 'check' => 'glpi_apiclients_is_active_boolean'],
            'is_recursive' => ['default' => 0, 'check' => 'glpi_apiclients_is_recursive_boolean'],
        ],
        'glpi_specialstatuses' => [
            'is_active' => ['default' => 1, 'check' => 'glpi_specialstatuses_is_active_boolean'],
        ],
        'glpi_requesttypes' => [
            'is_active' => ['default' => 1, 'check' => 'glpi_requesttypes_is_active_boolean'],
            'is_ticketheader' => ['default' => 1, 'check' => 'glpi_requesttypes_is_ticketheader_boolean'],
            'is_itilfollowup' => ['default' => 1, 'check' => 'glpi_requesttypes_is_itilfollowup_boolean'],
        ],
        'glpi_planningexternaleventtemplates' => [
            'background' => ['default' => 0, 'check' => 'glpi_planningexternaleventtemplates_background_boolean'],
        ],
        'glpi_lineoperators' => [
            'is_recursive' => ['default' => 0, 'check' => 'glpi_lineoperators_is_recursive_boolean'],
        ],
        'glpi_lines' => [
            'is_recursive' => ['default' => 0, 'check' => 'glpi_lines_is_recursive_boolean'],
            'is_deleted' => ['default' => 0, 'check' => 'glpi_lines_is_deleted_boolean'],
        ],
        'glpi_planningexternalevents' => [
            'is_recursive' => ['default' => 1, 'check' => 'glpi_planningexternalevents_is_recursive_boolean'],
            'background' => ['default' => 0, 'check' => 'glpi_planningexternalevents_background_boolean'],
        ],
        'glpi_itilfollowuptemplates' => [
            'is_recursive' => ['default' => 0, 'check' => 'glpi_itilfollowuptemplates_is_recursive_boolean'],
            'is_private' => ['default' => 0, 'check' => 'glpi_itilfollowuptemplates_is_private_boolean'],
        ],
    ];

    public function version(): string
    {
        return self::VERSION;
    }

    public function plan(Connection $connection): array
    {
        $catalog = BooleanDomainSchema::catalog($connection);
        $platform = $connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof PostgreSQLPlatform) {
            throw new RuntimeException('Unsupported nonnullable flag migration platform.');
        }
        $quote = $platform->quoteIdentifier(...);
        $problems = $sql = [];
        foreach (self::FLAGS as $table => $flags) {
            $predicates = $changes = [];
            foreach ($flags as $column => $definition) {
                $default = $definition['default'];
                $key = $table . '.' . $column;
                $actual = $catalog['columns'][$table][$column] ?? null;
                if ($actual === null) {
                    $problems[] = 'Missing nonnullable flag: ' . $key;
                    continue;
                }
                $native = !$catalog['mysql'] && $actual['data_type'] === 'boolean';
                if (!$native && $actual['data_type'] !== 'smallint') {
                    $problems[] = 'Changed nonnullable flag storage: ' . $key;
                    continue;
                }
                if ($actual['is_nullable'] !== 'NO') {
                    $problems[] = 'Changed nonnullable flag nullability: ' . $key;
                }
                $expected = $native ? ($default ? 'true' : 'false') : (string)$default;
                $literal = (string)$actual['column_default'];
                $quoted = "'" . $expected . "'";
                $validDefault = $literal === $expected || ($catalog['mysql'] && $literal === $quoted)
                    || (!$catalog['mysql'] && $literal === $quoted . ($native ? '::boolean' : '::smallint'));
                if (!$validDefault) {
                    $problems[] = 'Changed nonnullable flag default: ' . $key;
                }
                $field = $quote($column);
                $predicates[$column] = $field . ' IS NULL' . ($native ? '' : ' OR ' . $field . ' NOT IN (0, 1)');
                if (!$catalog['mysql']) {
                    if (!$native) {
                        $changes[] = 'ALTER COLUMN ' . $field . ' DROP DEFAULT';
                        $changes[] = 'ALTER COLUMN ' . $field . ' TYPE BOOLEAN USING (' . $field . ' = 1)';
                        $changes[] = 'ALTER COLUMN ' . $field . ' SET DEFAULT ' . ($default ? 'TRUE' : 'FALSE');
                    }
                    continue;
                }
                $name = $definition['check'];
                $check = $catalog['checks'][$table][$name] ?? null;
                if ($check === null) {
                    $changes[] = 'ADD CONSTRAINT ' . $quote($name) . ' CHECK (' . $field . ' IS NOT NULL AND ' . $field . ' IN (0, 1))'
                        . ($platform instanceof MySQLPlatform ? ' ENFORCED' : '');
                } elseif (!BooleanCheckExpression::matches($check['clause'], $column, false, $catalog['ansi_quotes'])) {
                    $problems[] = 'Conflicting nonnullable flag CHECK: ' . $key;
                } elseif ($check['enforced'] !== 'YES') {
                    $problems[] = 'Unenforced nonnullable flag CHECK: ' . $key;
                }
            }
            if ($predicates) {
                $counts = [];
                foreach (array_values($predicates) as $index => $predicate) {
                    $counts[] = 'SUM(CASE WHEN ' . $predicate . ' THEN 1 ELSE 0 END) AS f' . $index;
                }
                $counts = $connection->fetchAssociative('SELECT ' . implode(', ', $counts) . ' FROM ' . $quote($table));
                foreach (array_keys($predicates) as $index => $column) {
                    $count = (int)($counts['f' . $index] ?? 0);
                    if ($count !== 0) {
                        $samples = $connection->fetchAllAssociative('SELECT ' . $quote('id') . ', ' . $quote($column) . ' FROM ' . $quote($table)
                            . ' WHERE ' . $predicates[$column] . ' ORDER BY ' . $quote('id') . ' LIMIT 5');
                        $problems[] = 'Invalid nonnullable flag data: ' . $table . '.' . $column . ' (' . $count . ' rows); samples: '
                            . json_encode($samples, JSON_THROW_ON_ERROR);
                    }
                }
            }
            if ($changes) {
                $sql[] = 'ALTER TABLE ' . $quote($table) . ' ' . implode(', ', $changes);
            }
        }
        if ($problems) {
            throw new RuntimeException("Nonnullable flag preflight failed before owned DDL:\n" . implode("\n", $problems));
        }
        return ['sql' => $sql, 'requires_offline_application_writers' => $catalog['mysql']];
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            if ($connection->isTransactionActive()) {
                throw new RuntimeException('Nonnullable flag DDL requires no MySQL application transaction; stop all application writers before migration.');
            }
            // Native ADD CHECK validates current data under its DDL lock. A write
            // racing preflight cannot be cast away: invalid data refuses the DDL.
            // Stop ALL application/cron/import writers for the complete release;
            // History GET_LOCK serializes runners, not those application writers.
            $this->applyPlan($connection, $progress);
            return;
        }
        OwnedMutationFrame::run($connection, function () use ($connection, $progress): void {
            $quote = $connection->getDatabasePlatform()->quoteIdentifier(...);
            // Acquire every cohort table before the audit/cast window. Locks
            // survive our savepoint until the actual enclosing owner commits.
            $connection->executeStatement('LOCK TABLE ' . implode(', ', array_map($quote, array_keys(self::FLAGS))) . ' IN ACCESS EXCLUSIVE MODE');
            $this->applyPlan($connection, $progress);
        });
    }

    private function applyPlan(Connection $connection, ?callable $progress): void
    {
        // Never trust a phase receipt instead of all-cohort native preflight,
        // including retries after MySQL committed only a prefix of the DDL.
        $plan = $this->plan($connection);
        foreach ($plan['sql'] as $statement) {
            $connection->executeStatement($statement);
            $progress && $progress('Converted nonnullable flag table');
        }
        $this->verify($connection);
    }

    public function verify(Connection $connection): void
    {
        if ($this->plan($connection)['sql']) {
            throw new RuntimeException('Nonnullable flag migration did not converge.');
        }
        // The new terminal release retains its predecessor's owning proof.
        (new ComponentParentExpansions())->verify($connection);
    }
}
