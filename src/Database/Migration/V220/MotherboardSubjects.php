<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen Motherboard ownership reuses the immutable 20261012 DDL and retry producer. */
final class MotherboardSubjects extends StagedTypedItemMigration
{
    use ComponentSubjectMigration;

    public const PHASE = '20261013_motherboard_subjects';

    private static ?array $snapshot = null;

    private static function snapshot(): array
    {
        return self::$snapshot ??= json_decode(file_get_contents(__DIR__ . '/history/20261013-motherboard-subjects.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function unsupportedKindGuidance(): string
    {
        return 'Motherboard assignments require a persisted declared asset or unassigned stock with a blank/null kind and zero/null identity. Resolve unsupported source kinds before adoption; the canonical importer cannot bypass this preflight.';
    }
}
