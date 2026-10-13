<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/** Frozen identity scope includes scalar and polymorphic references, without inventing FKs. */
final class IdentifierColumns
{
    private static ?array $history = null;

    public static function history(): array
    {
        return self::$history ??= json_decode(file_get_contents(__DIR__ . '/history/20261001-legacy-to-orm.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function configureSchema(Schema $schema): void
    {
        foreach (self::history()['identifiers'] as $name => $columns) {
            if (!$schema->hasTable($name)) {
                continue;
            }
            foreach ($columns as $nameOfColumn) {
                $table = $schema->getTable($name);
                if (!$table->hasColumn($nameOfColumn)) {
                    continue;
                }
                $column = $table->getColumn($nameOfColumn);
                $column->setType(Type::getType(Types::BIGINT));
                if ($column->getColumnDefinition() !== null) {
                    $column->setColumnDefinition(preg_replace('/^(?:INTEGER|INT)\b/i', 'BIGINT', $column->getColumnDefinition()));
                }
            }
        }
    }
}
