<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use RuntimeException;

/** A required assignment, capacity or aggregate mutation was refused. */
final class SoftwareAssignmentCancelled extends RuntimeException
{
    public static function requireSuccess(mixed $result, string $operation): void
    {
        if ($result !== true && $result !== 1) {
            throw new self($operation . ' was cancelled.');
        }
    }

    public static function requireIdentifier(mixed $result, string $operation): int
    {
        if ((!is_int($result) && !is_string($result))
            || filter_var($result, FILTER_VALIDATE_INT) === false || (int)$result <= 0) {
            throw new self($operation . ' did not produce a valid identity.');
        }
        return (int)$result;
    }
}
