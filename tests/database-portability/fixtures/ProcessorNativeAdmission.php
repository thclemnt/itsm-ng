<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/** Processor contract cause matching; no application policy or native SQL mutation. */
final class ProcessorNativeAdmission
{
    public static function selectedCheck(DriverException $error, string $table, string $database): bool
    {
        return NativeConstraintRefusal::matchesTypedSubjectCheck($error, $table, $database);
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
            $recognized = $error !== null && NativeConstraintRefusal::matchesGeneratedProjection($error, $table, $operationName);
            verify($recognized, 'Native generated ' . $operationName . ' must refuse the selected processor projection: ' . $table
                . '; observed ' . ($error === null ? 'accepted' : $error::class . '/' . $error->getCode() . '/' . $error->getSQLState()));
        } finally {
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }
        }
    }
}
