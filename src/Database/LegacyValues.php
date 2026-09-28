<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Decode the old application's pre-escaping once, before binding a Doctrine value. */
final class LegacyValues
{
    public static function decode(mixed $value): mixed
    {
        if ($value === 'NULL' || $value === 'null') {
            return null;
        }
        if (!is_string($value)) {
            return $value;
        }
        return preg_replace_callback('/\\\\(.)/s', static fn ($m) => match ($m[1]) {
            'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", '0' => "\0", 'Z' => "\x1a",
            '%', '_' => $m[0], default => $m[1],
        }, $value);
    }
}
