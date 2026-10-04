<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** Match only the actual PDO-native selected constraint or generated projection cause. */
final class ComponentNativeAdmission
{
    public static function reject(Connection $connection, callable $operation, string $table, string $cause, ?string $constraint = null, ?string $parent = null): void
    {
        $frame = \itsmng\Database\OwnedMutationFrame::begin($connection);
        $primary = $cleanup = null;
        try {
            $error = null;
            try {
                $operation();
            } catch (DriverException $caught) {
                $error = $caught;
            }
            $driver = $error?->getPrevious();
            $pdo = $driver instanceof PdoDriverException ? $driver->getPrevious() : null;
            $info = $pdo instanceof PDOException ? $pdo->errorInfo : null;
            $recognized = is_array($info) && count($info) === 3 && is_string($info[2] ?? null)
                && ($info[0] ?? null) === $error?->getSQLState()
                && ($info[1] ?? null) === $error?->getCode();
            if ($recognized) {
                $message = $info[2];
                if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                    $line = explode("\n", $message, 2)[0];
                    $native = preg_replace('/^ERROR:\s+/', '', $line);
                    $recognized = match ($cause) {
                        'check' => $error->getSQLState() === '23514'
                            && $native === 'new row for relation "' . $table . '" violates check constraint "' . $constraint . '"',
                        'foreign' => $error instanceof ForeignKeyConstraintViolationException && $error->getSQLState() === '23503'
                            && $native === 'insert or update on table "' . $table . '" violates foreign key constraint "' . $constraint . '"',
                        'parent-foreign' => self::matchesPostgresParentForeign($error, $native, $table, $constraint, $parent),
                        'generated-insert' => $error->getSQLState() === '428C9' && $native === 'cannot insert a non-DEFAULT value into column "items_id"',
                        'generated-update' => $error->getSQLState() === '428C9' && $native === 'column "items_id" can only be updated to DEFAULT',
                        default => false,
                    };
                } else {
                    $database = $connection->getDatabase();
                    $recognized = match ($cause) {
                        'check' => ($error->getSQLState() === '23000' && $error->getCode() === 4025
                            && in_array($message, [
                                'CONSTRAINT `' . $constraint . '` failed for `' . $database . '`.`' . $table . '`',
                                'CONSTRAINT `' . $database . '.' . $constraint . '` failed for `' . $database . '`.`' . $table . '`',
                            ], true)) || ($error->getSQLState() === 'HY000' && $error->getCode() === 3819
                                && $message === "Check constraint '" . $constraint . "' is violated."),
                        'foreign' => $error instanceof ForeignKeyConstraintViolationException && $error->getSQLState() === '23000'
                            && $error->getCode() === 1452 && str_starts_with($message, 'Cannot add or update a child row: a foreign key constraint fails (')
                            && str_contains($message, '`' . $database . '`.`' . $table . '`')
                            && str_contains($message, 'CONSTRAINT `' . $constraint . '` FOREIGN KEY'),
                        'parent-foreign' => $error instanceof ForeignKeyConstraintViolationException && $error->getSQLState() === '23000'
                            && $error->getCode() === 1451 && str_starts_with($message, 'Cannot delete or update a parent row: a foreign key constraint fails (')
                            && str_contains($message, '`' . $database . '`.`' . $table . '`')
                            && str_contains($message, 'CONSTRAINT `' . $constraint . '` FOREIGN KEY'),
                        'generated-insert', 'generated-update' => $error->getSQLState() === 'HY000'
                            && in_array($error->getCode(), [1906, 3105], true)
                            && in_array($message, [
                                "The value specified for generated column 'items_id' in table '" . $table . "' is not allowed.",
                                "The value specified for generated column 'items_id' in table '" . $table . "' has been ignored",
                            ], true),
                        default => false,
                    };
                }
            }
            verify($recognized, 'Actual PDO-native selected component cause required: ' . $cause . '/' . $table . '/' . ($constraint ?? 'items_id')
                . '; observed ' . ($error === null ? 'accepted' : $error::class . '/' . $error->getCode() . '/' . $error->getSQLState()));
        } catch (Throwable $error) {
            $primary = $error;
        } finally {
            try {
                $frame->rollBack();
            } catch (Throwable $error) {
                $cleanup = $error;
            }
        }
        if ($primary !== null) {
            throw $cleanup === null ? $primary : new \itsmng\Database\MutationCleanupFailure($primary, $cleanup, true);
        }
        if ($cleanup !== null) {
            throw $cleanup;
        }
    }

    /** Both server forms retain the selected parent, child and owning constraint. */
    public static function matchesPostgresParentForeign(DriverException $error, string $native, string $table, ?string $constraint, ?string $parent): bool
    {
        return $constraint !== null && $parent !== null && $error instanceof ForeignKeyConstraintViolationException
            && match ($error->getSQLState()) {
                '23503' => $native === 'update or delete on table "' . $parent . '" violates foreign key constraint "' . $constraint . '" on table "' . $table . '"',
                '23001' => $native === 'update or delete on table "' . $parent . '" violates RESTRICT setting of foreign key constraint "' . $constraint . '" on table "' . $table . '"',
                default => false,
            };
    }
}
