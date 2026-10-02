<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** A selected, required transfer mutation was refused by its public lifecycle. */
final class TransferCancelled extends \RuntimeException
{
    public static function requireWrite(mixed $result, string $operation): mixed
    {
        if ($result !== true && $result !== 1) {
            throw new self('Transfer refused: ' . $operation);
        }
        return $result;
    }

    public static function requireIdentifier(mixed $result, string $operation): int
    {
        $identifier = (is_int($result) || is_string($result))
            ? filter_var($result, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($identifier === false) {
            throw new self('Transfer could not create or import: ' . $operation);
        }
        return $identifier;
    }

    /** Legacy transferItem overrides can return void; an explicit refusal must propagate. */
    public static function requireTransfer(mixed $result): void
    {
        if ($result === false) {
            throw new self('A related item transfer was refused');
        }
    }
}
