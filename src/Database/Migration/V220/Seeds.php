<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Migration\Ledger;
use RuntimeException;

/** Frozen raw seed import before adoption, independent of current ORM metadata. */
final class Seeds
{
    public const PHASE = '2.2.0.baseline_seed';

    public static function rows(?callable $translate = null): array
    {
        $translate ??= static fn (string $message): string => $message;
        return require __DIR__ . '/history/20261001-seeds.php';
    }

    /** Validate every explicit seed before writing; never infer legacy implicit defaults. */
    public static function prepare(Schema $schema, array $rows): array
    {
        foreach ($rows as $name => $records) {
            $table = $schema->getTable($name);
            foreach ($records as $record) {
                foreach ($record as $field => $value) {
                    $column = $table->getColumn($field);
                    if ($column->getNotnull() && $value === null) {
                        throw new RuntimeException('Historical seed supplies NULL for required field: ' . $name . '.' . $field);
                    }
                    if (Type::lookupName($column->getType()) === Types::BOOLEAN && $value !== null && !in_array($value, [0, 1, '0', '1', false, true], true)) {
                        throw new RuntimeException('Invalid historical boolean seed: ' . $name . '.' . $field);
                    }
                }
                foreach ($table->getColumns() as $column) {
                    if ($column->getNotnull() && !$column->getAutoincrement() && $column->getDefault() === null && !array_key_exists($column->getName(), $record)) {
                        throw new RuntimeException('Historical seed omits required field: ' . $name . '.' . $column->getName());
                    }
                }
            }
        }
        return $rows;
    }

    public function apply(Connection $connection, ?callable $translate = null, ?callable $progress = null): void
    {
        if ((Ledger::state($connection, self::PHASE)['complete'] ?? false) === true) {
            return;
        }
        $schema = (new Baseline())->build($connection->getDatabasePlatform());
        // Validate the entire seed plan against its historical schema before writing.
        $rows = self::prepare($schema, self::rows($translate));
        Ledger::save($connection, self::PHASE, ['complete' => false, 'origin' => 'installed']);
        $connection->transactional(static function () use ($connection, $schema, $rows, $progress): void {
            foreach ($rows as $name => $records) {
                foreach ($records as $record) {
                    $types = [];
                    foreach ($record as $field => &$value) {
                        if (Type::lookupName($schema->getTable($name)->getColumn($field)->getType()) === Types::BOOLEAN && $value !== null) {
                            $value = (bool)(int)$value;
                            $types[$field] = ParameterType::BOOLEAN;
                        }
                    }
                    unset($value);
                    $quoted = $quotedTypes = [];
                    foreach ($record as $field => $value) {
                        $key = $connection->getDatabasePlatform()->quoteIdentifier($field);
                        $quoted[$key] = $value;
                        if (isset($types[$field])) {
                            $quotedTypes[$key] = $types[$field];
                        }
                    }
                    $connection->insert($connection->getDatabasePlatform()->quoteIdentifier($name), $quoted, $quotedTypes);
                    $progress && $progress();
                }
            }
            // Seed DML and its completion record commit together on both engines.
            Ledger::save($connection, self::PHASE, ['complete' => true, 'origin' => 'installed']);
        });
    }
}
