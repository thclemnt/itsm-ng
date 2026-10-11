<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;

/** Decode the old application's pre-escaping once, before binding a Doctrine value. */
final class LegacyValues
{
    /** Canonical mapped text owns literal strings, including the word NULL. */
    public static function isTextType(?string $type): bool
    {
        if ($type === null) {
            return false;
        }
        $mapping = Type::getType($type);
        return $mapping instanceof StringType || $mapping instanceof TextType;
    }

    public static function decode(mixed $value): mixed
    {
        if ($value === 'NULL' || $value === 'null') {
            return null;
        }
        if (!is_string($value)) {
            return $value;
        }
        return self::decodeString($value);
    }

    /** Names and other typed string inputs do not use the legacy NULL sentinel. */
    public static function decodeString(string $value): string
    {
        return preg_replace_callback('/\\\\(.)/s', static fn ($m) => match ($m[1]) {
            'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", '0' => "\0", 'Z' => "\x1a",
            '%', '_' => $m[0], default => $m[1],
        }, $value);
    }
}
