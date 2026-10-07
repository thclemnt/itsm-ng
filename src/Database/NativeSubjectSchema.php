<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use RuntimeException;

/** Live native enforcement for subject policies produced by the current schema owner. */
final class NativeSubjectSchema
{
    public static function differences(Connection $connection, array $policies): array
    {
        if (!$policies) {
            return [];
        }
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $columns = $checks = [];
        $parameters = [array_keys($policies)];
        $types = [ArrayParameterType::STRING];
        if ($mysql) {
            // One catalogue for all subject families; no per-table introspection
            // and no retained receipt is treated as the current declaration.
            try {
                $catalog = BooleanDomainSchema::catalog($connection);
            } catch (RuntimeException $error) {
                return ['Native subject enforcement unavailable: ' . $error->getMessage()];
            }
            $checks = $catalog['checks'];
            $ansiQuotes = $catalog['ansi_quotes'];
            $rows = $connection->fetchAllAssociative(
                'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, '
                . 'EXTRA AS `generated`, GENERATION_EXPRESSION AS `expression` FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?)',
                $parameters,
                $types
            );
        } else {
            $ansiQuotes = false;
            $rows = $connection->fetchAllAssociative(
                'SELECT t.relname AS table_name, a.attname AS column_name, '
                . 'a.attgenerated AS generated, pg_get_expr(d.adbin, d.adrelid) AS expression, c.collisdeterministic AS deterministic '
                . 'FROM pg_catalog.pg_class t JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid '
                . 'LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = t.oid AND d.adnum = a.attnum '
                . 'LEFT JOIN pg_catalog.pg_collation c ON c.oid = a.attcollation '
                . 'WHERE t.relkind IN (\'r\', \'p\') AND a.attnum > 0 AND NOT a.attisdropped AND pg_catalog.pg_table_is_visible(t.oid) AND t.relname IN (?)',
                $parameters,
                $types
            );
            foreach ($connection->fetchAllAssociative(
                'SELECT t.relname AS table_name, c.conname AS constraint_name, '
                . 'pg_get_expr(c.conbin, c.conrelid) AS clause, c.convalidated AS validated, '
                // conenforced is new in PostgreSQL 18; older versions always enforce CHECKs.
                . "COALESCE(to_jsonb(c)->>'conenforced', 'true') AS enforced "
                . 'FROM pg_catalog.pg_constraint c JOIN pg_catalog.pg_class t ON t.oid = c.conrelid '
                . "WHERE c.contype = 'c' AND pg_catalog.pg_table_is_visible(t.oid) AND t.relname IN (?)",
                $parameters,
                $types
            ) as $check) {
                $checks[$check['table_name']][$check['constraint_name']] = $check;
            }
        }
        foreach ($rows as $column) {
            $columns[$column['table_name']][$column['column_name']] = $column;
        }
        return self::compare($policies, $columns, $checks, !$mysql, $ansiQuotes);
    }

    /** Compare one operation's immutable catalog snapshot, retaining SQL literal and operator semantics. */
    public static function compare(array $policies, array $columns, array $checks, bool $postgres, bool $ansiQuotes = false): array
    {
        $differences = [];
        foreach ($policies as $table => $fields) {
            foreach ($fields as $column => $policy) {
                $name = $policy['constraint'];
                $check = $checks[$table][$name] ?? null;
                $verifiedCheck = $check !== null && self::isTrue($check['enforced'] ?? null)
                    && (!$postgres || self::isTrue($check['validated'] ?? null))
                    && SubjectPolicyExpression::equivalent($policy['check'], $check['clause'] ?? '', $postgres, $ansiQuotes);
                $native = $columns[$table][$column] ?? null;
                $stored = $postgres ? ($native['generated'] ?? null) === 's'
                    : preg_match('/(?:^|\s)STORED GENERATED(?:\s|$)/iD', $native['generated'] ?? '') === 1;
                if (!$stored || !is_string($native['expression'] ?? null)
                    || !SubjectPolicyExpression::equivalent($policy['projection'], $native['expression'], $postgres, $ansiQuotes, $verifiedCheck ? $policy['check'] : null)) {
                    $differences[] = 'Changed or missing native subject projection: ' . $table . '.' . $column;
                }
                if (!$verifiedCheck) {
                    $differences[] = 'Changed, missing or unenforced native subject CHECK: ' . $table . '.' . $name;
                }
                if ($postgres) {
                    foreach ($policy['discriminators'] as $discriminator) {
                        if (!self::isTrue($columns[$table][$discriminator]['deterministic'] ?? null)) {
                            $differences[] = 'Expected deterministic subject discriminator: ' . $table . '.' . $discriminator;
                        }
                    }
                }
            }
        }
        return $differences;
    }

    private static function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true', 'YES'], true);
    }
}
