<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Driver\Mysqli\Exception\StatementError;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;

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
        if (!is_array($information) || count($information) !== 3
            || ($information[0] ?? null) !== $error->getSQLState()
            || ($information[1] ?? null) !== $error->getCode()
            || !is_string($information[2] ?? null)) {
            return null;
        }
        return $information[2];
    }
}
