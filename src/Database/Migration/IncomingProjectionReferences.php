<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** Native incoming items_id references for one read-only migration planning call. */
final class IncomingProjectionReferences
{
    private ?array $references = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function has(string $schema, string $table): bool
    {
        if ($this->references === null) {
            // PostgreSQL constraint names need not be unique within a schema.
            // Resolve the referenced relation and attributes by their native IDs.
            // Keep references from every referencing schema/database, including
            // custom tables outside the application schema.
            $sql = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
                ? "SELECT n.nspname AS referenced_schema, r.relname AS referenced_table
                   FROM pg_catalog.pg_constraint f
                   JOIN pg_catalog.pg_class r ON r.oid = f.confrelid
                   JOIN pg_catalog.pg_namespace n ON n.oid = r.relnamespace
                   JOIN pg_catalog.pg_attribute a ON a.attrelid = r.oid AND a.attnum = ANY(f.confkey)
                   WHERE f.contype = 'f' AND a.attname = 'items_id'"
                : "SELECT referenced_table_schema AS referenced_schema, referenced_table_name AS referenced_table
                   FROM information_schema.key_column_usage WHERE referenced_column_name = 'items_id'";
            $this->references = [];
            foreach ($this->connection->fetchAllAssociative($sql) as $reference) {
                $this->references[$reference['referenced_schema']][$reference['referenced_table']] = true;
            }
        }
        return isset($this->references[$schema][$table]);
    }
}
