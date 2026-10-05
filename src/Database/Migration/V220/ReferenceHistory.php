<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen upgrade inputs. Historical migrations never inspect current ORM entities. */
final class ReferenceHistory
{
    private static ?array $definitions = null;

    public static function get(string $section, string $name = 'RELATIONS'): array
    {
        self::$definitions ??= json_decode(file_get_contents(__DIR__ . '/history/20260930-reference-upgrades.json'), true, flags: JSON_THROW_ON_ERROR);
        return self::$definitions[$section][$name] ?? throw new \InvalidArgumentException('Unknown historical reference definition: ' . $section . '.' . $name);
    }
}
