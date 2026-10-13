<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;

/** Read native projection metadata without comparing MySQL's computed EXTRA collation. */
final class MySQLGeneratedColumnInspection
{
    public static function isGeneratedExtra(string $extra): bool
    {
        // DEFAULT_GENERATED describes a default expression, not a generated column.
        // INVISIBLE and other native attributes may follow the projection marker.
        return preg_match('/(?:^|\s)(?:VIRTUAL|STORED) GENERATED(?:\s|$)/', $extra) === 1;
    }

    public static function isGenerated(Connection $connection, string $schema, string $table, string $column): bool
    {
        $extra = $connection->fetchOne(
            'SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$schema, $table, $column]
        );
        return $extra !== false && self::isGeneratedExtra($extra);
    }

    /** Native ordinal order and raw expressions are required for safe identifier widening. */
    public static function listGeneratedColumns(Connection $connection, string $schema, string $table): array
    {
        $generated = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT COLUMN_NAME AS column_name, GENERATION_EXPRESSION AS generation_expression, EXTRA AS extra'
                . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$schema, $table]
        ) as $column) {
            if (self::isGeneratedExtra($column['extra'])) {
                $generated[] = ['column_name' => $column['column_name'], 'generation_expression' => $column['generation_expression']];
            }
        }
        return $generated;
    }
}
