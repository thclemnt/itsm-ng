<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen Battery ownership reuses the immutable 20261012 DDL and retry producer. */
final class BatterySubjects extends StagedTypedItemMigration
{
    use ComponentSubjectMigration;

    public const PHASE = '20261014_battery_subjects';

    private static ?array $snapshot = null;

    private static function snapshot(): array
    {
        return self::$snapshot ??= json_decode(file_get_contents(__DIR__ . '/history/20261014-battery-subjects.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function unsupportedKindGuidance(): string
    {
        return 'Battery core adoption supports Computer, Peripheral, Phone and Printer subjects or unassigned stock with a blank/null kind and zero/null identity. A valid plugin subject may require a separately reviewed owning mapping and migration; retain its original source row and plugin schema. Unsupported kinds are not orphan proof, and the canonical importer cannot bypass this data-preserving refusal.';
    }
}
