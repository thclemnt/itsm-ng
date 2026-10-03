<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Mysqli\Exception\StatementError;
use Doctrine\DBAL\Driver\PgSQL\Exception as PostgreSQLDriverException;
use Doctrine\DBAL\Exception\DriverException;

/** Software contract cause matching; no application policy or native SQL mutation. */
final class SoftwareNativeAdmission
{
    public static function selectedCheck(DriverException $error, string $table, string $database): bool
    {
        $name = $table . '_typed_item_kind';
        if (NativeConstraintRefusal::matchesSelectedCheck($error, $name)) {
            return true;
        }
        $native = $error->getPrevious();
        if ($native instanceof PostgreSQLDriverException) {
            return $error->getSQLState() === '23514' && $native->getSQLState() === '23514'
                && $native->getMessage() === 'new row for relation "' . $table . '" violates check constraint "' . $name . '"';
        }
        return $native instanceof StatementError && $error->getSQLState() === '23000'
            && $native->getSQLState() === '23000' && $error->getCode() === 4025 && $native->getCode() === 4025
            && in_array($native->getMessage(), [
                'CONSTRAINT `' . $name . '` failed for `' . $database . '`.`' . $table . '`',
                'CONSTRAINT `' . $database . '.' . $name . '` failed for `' . $database . '`.`' . $table . '`',
            ], true);
    }

    /** Never admit UNIQUE, CHECK, unknown HY000, or an ignored generated write. */
    public static function rejectGenerated(Connection $connection, callable $operation, string $table, string $operationName): void
    {
        $level = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            $error = null;
            try {
                $operation();
            } catch (DriverException $caught) {
                $error = $caught;
            }
            $native = $error?->getPrevious();
            $recognized = false;
            if ($native instanceof PostgreSQLDriverException) {
                $recognized = $error->getSQLState() === '428C9' && $native->getSQLState() === '428C9'
                    && $native->getMessage() === ($operationName === 'INSERT'
                        ? 'cannot insert a non-DEFAULT value into column "items_id"'
                        : 'column "items_id" can only be updated to DEFAULT');
            } elseif ($native instanceof StatementError) {
                // The actual generated-column cause, not a guessed numeric code.
                // Both messages identify this field/table and reject only a value
                // supplied for its generated projection under the strict factory.
                $recognized = $error->getSQLState() === 'HY000' && $native->getSQLState() === 'HY000'
                    && in_array($native->getMessage(), [
                        "The value specified for generated column 'items_id' in table '" . $table . "' is not allowed.",
                        "The value specified for generated column 'items_id' in table '" . $table . "' has been ignored",
                    ], true);
            }
            verify($recognized, 'Native generated ' . $operationName . ' must refuse the selected software projection: ' . $table
                . '; observed ' . ($error === null ? 'accepted' : $error::class . '/' . $error->getCode() . '/' . $error->getSQLState()));
        } finally {
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }
        }
    }
}
