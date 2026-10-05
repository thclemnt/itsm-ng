<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;

/** Match the selected PostgreSQL parent-refusal diagnostic. */
final class ComponentNativeAdmission
{
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
