<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\Mapping\NativeTimestamp;
use ReflectionProperty;

/** Read-only native touch inspection, derived from the owning property declaration. */
final class NativeTimestampSchema
{
    public static function declarations(iterable $metadata): array
    {
        $declarations = [];
        foreach ($metadata as $entity) {
            foreach ($entity->fieldMappings as $property => $field) {
                foreach ((new ReflectionProperty($entity->name, $property))->getAttributes(NativeTimestamp::class) as $attribute) {
                    $declarations[$entity->getTableName()][trim($field->columnName, '`"')] = $attribute->newInstance();
                }
            }
        }
        return $declarations;
    }

    /** Replace existing marked fields from ORM schema metadata; retained indexes keep their identity. */
    public static function replaceOwnedColumns(Table $table, Table $declaration, array $timestamps): void
    {
        foreach ($timestamps as $name => $timestamp) {
            if (!$table->hasColumn($name)) {
                continue; // The ordinary new-field projection adds missing properties.
            }
            $column = $declaration->getColumn($name);
            $options = $column->toArray(true);
            unset($options['name']);
            $options = array_filter($options, static fn ($name) => method_exists($column, 'set' . $name), ARRAY_FILTER_USE_KEY);
            $table->modifyColumn($name, $options);
        }
    }

    /** Optional metadata supports a separately owned ORM schema, without a second field catalogue. */
    public static function differences(Connection $connection, Schema $expected, ?iterable $metadata = null): array
    {
        $declarations = $metadata === null ? EntityRegistry::nativeTimestamps() : self::declarations($metadata);
        $touches = [];
        foreach ($declarations as $table => $fields) {
            foreach ($fields as $column => $timestamp) {
                if ($timestamp->touchTrigger !== null && $expected->hasTable($table) && $expected->getTable($table)->hasColumn($column)) {
                    $touches[$table][$column] = ['trigger' => $timestamp->touchTrigger,
                        'body' => $timestamp->touchBody($connection->getDatabasePlatform(), $column)];
                }
            }
        }
        return self::touchDifferences($connection, $touches);
    }

    /** Native inspection shared by current declarations and explicit frozen release inputs. */
    public static function touchDifferences(Connection $connection, array $touches): array
    {
        $platform = $connection->getDatabasePlatform();
        $differences = [];
        foreach ($touches as $table => $fields) {
            foreach ($fields as $column => $touch) {
                if ($platform instanceof AbstractMySQLPlatform) {
                    $extra = $connection->fetchOne(
                        'SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                        [$table, $column]
                    );
                    // A missing column has its own structural diagnostic.
                    if ($extra !== false && preg_match('/(?:^|\s)on update CURRENT_TIMESTAMP(?:\(\))?(?:\s|$)/iD', $extra) !== 1) {
                        $differences[] = 'Expected automatic timestamp touch: ' . $table . '.' . $column;
                    }
                } elseif ($platform instanceof PostgreSQLPlatform) {
                    $trigger = $connection->fetchAssociative(
                        "SELECT t.tgtype, t.tgenabled, t.tgnargs, t.tgattr = ''::int2vector AS all_columns, t.tgqual IS NULL AS no_when, "
                        . 'p.proname, p.prosrc, p.prosecdef, p.proconfig IS NULL AS no_settings, '
                        . 'p.pronamespace = c.relnamespace AS local_function, l.lanname '
                        . 'FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_proc p ON p.oid = t.tgfoid '
                        . 'JOIN pg_language l ON l.oid = p.prolang '
                        . 'WHERE t.tgrelid = to_regclass(?) AND t.tgname = ? AND NOT t.tgisinternal',
                        [$platform->quoteIdentifier($table), $touch['trigger']]
                    );
                    $true = static fn ($value): bool => in_array($value, [true, 1, '1', 't', 'true'], true);
                    $false = static fn ($value): bool => in_array($value, [false, 0, '0', 'f', 'false'], true);
                    $normalize = static fn (string $body): string => preg_replace('/\s+/', ' ', trim($body));
                    if (
                        !$trigger || (int)$trigger['tgtype'] !== 19 || !in_array($trigger['tgenabled'], ['O', 'A'], true)
                        || (int)$trigger['tgnargs'] !== 0 || !$true($trigger['all_columns']) || !$true($trigger['no_when']) || !$true($trigger['local_function'])
                        || !$false($trigger['prosecdef']) || !$true($trigger['no_settings']) || $trigger['lanname'] !== 'plpgsql'
                        || $trigger['proname'] !== $touch['trigger']
                        || $normalize($trigger['prosrc']) !== $normalize($touch['body'])
                    ) {
                        $differences[] = 'Expected automatic timestamp touch: ' . $table . '.' . $column;
                    }
                }
            }
        }
        return $differences;
    }
}
