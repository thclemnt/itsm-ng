<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** Advance real serial/identity sequences after assigned-ID imports, never rewind them. */
final class SequenceSynchronizer
{
    public static function synchronize(Connection $connection): void
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return; // MySQL/MariaDB advance AUTO_INCREMENT on explicit-ID inserts.
        }
        $quote = $connection->getDatabasePlatform()->quoteSingleIdentifier(...);
        // Both SERIAL and IDENTITY sequences have column ownership dependencies.
        // Resolve identifier components directly: formatted regclass names can
        // already contain quotes or periods and must never be quoted a second time.
        $sequences = $connection->fetchAllAssociative("SELECT tn.nspname AS table_schema, t.relname AS table_name, a.attname AS column_name,
            sn.nspname AS sequence_schema, s.relname AS sequence_name, s.oid::text AS sequence_oid
            FROM pg_class s JOIN pg_namespace sn ON sn.oid = s.relnamespace
            JOIN pg_depend d ON d.objid = s.oid AND d.classid = 'pg_class'::regclass
                AND d.refclassid = 'pg_class'::regclass AND d.deptype IN ('a', 'i')
            JOIN pg_class t ON t.oid = d.refobjid JOIN pg_namespace tn ON tn.oid = t.relnamespace
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
            WHERE s.relkind = 'S' AND t.relkind IN ('r', 'p') AND tn.nspname = ANY(current_schemas(false))");
        foreach ($sequences as $sequence) {
            $table = $quote($sequence['table_schema']) . '.' . $quote($sequence['table_name']);
            $relation = $quote($sequence['sequence_schema']) . '.' . $quote($sequence['sequence_name']);
            $state = $connection->fetchAssociative('SELECT last_value, is_called FROM ' . $relation);
            $maximum = $connection->fetchOne('SELECT MAX(' . $quote($sequence['column_name']) . ') FROM ' . $table);
            // Preserve unused sequences as well as IDs consumed by rolled-back
            // transactions or deleted rows. Maintenance callers stop writers.
            if ($maximum !== null && (int)$maximum >= (int)$state['last_value'] + ($state['is_called'] ? 1 : 0)) {
                $connection->executeStatement('SELECT setval(?::regclass, ?, true)', [$sequence['sequence_oid'], $maximum]);
            }
        }
    }
}
