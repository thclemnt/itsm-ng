<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/** Frozen raw seed import before adoption, independent of current ORM metadata. */
final class Seeds20261001
{
    public const VERSION = '20261001_baseline_seed';

    public static function rows(?callable $translate = null): array
    {
        $translate ??= static fn (string $message): string => $message;
        return require __DIR__ . '/history/20261001-seeds.php';
    }

    public function apply(Connection $connection, ?callable $translate = null, ?callable $progress = null): void
    {
        if ((Ledger::state($connection, self::VERSION)['complete'] ?? false) === true) {
            return;
        }
        $schema = (new Baseline20261001())->build($connection->getDatabasePlatform());
        $rows = self::rows($translate);
        // Validate the entire seed plan against its historical schema before writing.
        foreach ($rows as $name => $records) {
            $table = $schema->getTable($name);
            foreach ($records as $record) {
                foreach ($record as $field => $value) {
                    $column = $table->getColumn($field);
                    if (Type::lookupName($column->getType()) === Types::BOOLEAN && $value !== null && !in_array($value, [0, 1, '0', '1', false, true], true)) {
                        throw new \RuntimeException('Invalid historical boolean seed: ' . $name . '.' . $field);
                    }
                }
            }
        }
        Ledger::save($connection, self::VERSION, ['complete' => false, 'origin' => 'installed']);
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
            Ledger::save($connection, self::VERSION, ['complete' => true, 'origin' => 'installed']);
        });
    }
}
