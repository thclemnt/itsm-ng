<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Driver\Mysqli\Exception\StatementError;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Driver\PgSQL\Exception as PostgreSQLDriverException;

/** Recognize the constraint refusal expected by a particular invalid input. */
final class NativeConstraintRefusal
{
    /** Official MySQL uses generic HY000 for this precise, selected CHECK cause. */
    public static function matchesSelectedCheck(DriverException $error, ?string $expectedConstraint): bool
    {
        return $expectedConstraint !== null && $expectedConstraint !== ''
            && $error::class === DriverException::class
            && $error->getCode() === 3819
            && $error->getSQLState() === 'HY000'
            && (($error->getPrevious() instanceof StatementError
                && $error->getPrevious()->getMessage() === "Check constraint '$expectedConstraint' is violated.")
                || self::nativePdoMessage($error) === "Check constraint '$expectedConstraint' is violated.");
    }

    /** The selected owning CHECK, never a different integrity error or query text. */
    public static function matchesTypedSubjectCheck(DriverException $error, string $table, string $database): bool
    {
        $name = $table . '_typed_item_kind';
        if (self::matchesSelectedCheck($error, $name)) {
            return true;
        }
        $native = $error->getPrevious();
        if ($native instanceof PostgreSQLDriverException) {
            return $error->getSQLState() === '23514' && $native->getSQLState() === '23514'
                && $native->getMessage() === 'new row for relation "' . $table . '" violates check constraint "' . $name . '"';
        }
        if ($native instanceof StatementError) {
            return $error->getSQLState() === '23000' && $native->getSQLState() === '23000'
                && $error->getCode() === 4025 && $native->getCode() === 4025
                && self::mariaCheckMessage($native->getMessage(), $name, $table, $database);
        }
        $message = self::nativePdoMessage($error);
        if ($message === null || $error::class !== DriverException::class) {
            return false;
        }
        return ($error->getSQLState() === '23514' && $error->getCode() === 7
                && self::postgresPrimary($message) === 'new row for relation "' . $table . '" violates check constraint "' . $name . '"')
            || ($error->getSQLState() === '23000' && $error->getCode() === 4025
                && self::mariaCheckMessage($message, $name, $table, $database));
    }

    /** The generated items_id projection has its own selected native refusal. */
    public static function matchesGeneratedProjection(DriverException $error, string $table, string $operation): bool
    {
        if (!in_array($operation, ['INSERT', 'UPDATE'], true)) {
            return false;
        }
        $postgres = $operation === 'INSERT'
            ? 'cannot insert a non-DEFAULT value into column "items_id"'
            : 'column "items_id" can only be updated to DEFAULT';
        $mysql = [
            "The value specified for generated column 'items_id' in table '" . $table . "' is not allowed.",
            "The value specified for generated column 'items_id' in table '" . $table . "' has been ignored",
        ];
        $native = $error->getPrevious();
        if ($native instanceof PostgreSQLDriverException) {
            return $error->getSQLState() === '428C9' && $native->getSQLState() === '428C9'
                && $native->getMessage() === $postgres;
        }
        if ($native instanceof StatementError) {
            return $error->getSQLState() === 'HY000' && $native->getSQLState() === 'HY000'
                && in_array($native->getMessage(), $mysql, true);
        }
        $message = self::nativePdoMessage($error);
        if ($message === null || $error::class !== DriverException::class) {
            return false;
        }
        return ($error->getSQLState() === '428C9' && $error->getCode() === 7 && self::postgresPrimary($message) === $postgres)
            || ($error->getSQLState() === 'HY000' && in_array($error->getCode(), [1906, 3105], true)
                && in_array($message, $mysql, true));
    }

    private static function mariaCheckMessage(string $message, string $name, string $table, string $database): bool
    {
        return in_array($message, [
            'CONSTRAINT `' . $name . '` failed for `' . $database . '`.`' . $table . '`',
            'CONSTRAINT `' . $database . '.' . $name . '` failed for `' . $database . '`.`' . $table . '`',
        ], true);
    }

    /** PDO PostgreSQL retains its server severity and DETAIL/HINT separately by line. */
    private static function postgresPrimary(string $message): string
    {
        return preg_replace('/^ERROR:\\s+/', '', explode("\n", $message, 2)[0]);
    }

    public static function matches(DriverException $error, ?string $omittedRequiredColumn = null): bool
    {
        if (in_array($error->getSQLState(), ['23502', '23503', '23514', '23505', '23001', '23000'], true)) {
            return true;
        }

        // Strict MySQL/MariaDB report omitted NOT NULL fields as HY000/1364.
        // Match the converted class and the native cause, never an arbitrary
        // HY000 failure or text embedded in the SQL query or bound values.
        return $omittedRequiredColumn !== null
            && $error instanceof NotNullConstraintViolationException
            && $error->getCode() === 1364
            && $error->getSQLState() === 'HY000'
            && ($error->getPrevious()?->getMessage() === "Field '$omittedRequiredColumn' doesn't have a default value"
                || self::nativePdoMessage($error) === "Field '$omittedRequiredColumn' doesn't have a default value");
    }

    /** PDO's rendered exception contains a prefix; errorInfo retains the native diagnostic. */
    private static function nativePdoMessage(DriverException $error): ?string
    {
        $driver = $error->getPrevious();
        if (!$driver instanceof PdoDriverException || !$driver->getPrevious() instanceof PDOException) {
            return null;
        }
        $information = $driver->getPrevious()->errorInfo;
        if ($driver->getSQLState() !== $error->getSQLState() || $driver->getCode() !== $error->getCode()
            || !is_array($information) || count($information) !== 3
            || ($information[0] ?? null) !== $error->getSQLState()
            || ($information[1] ?? null) !== $error->getCode()
            || !is_string($information[2] ?? null)) {
            return null;
        }
        return $information[2];
    }
}
