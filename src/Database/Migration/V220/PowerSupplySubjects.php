<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen PowerSupply ownership reuses the immutable 20261012 DDL and retry producer. */
final class PowerSupplySubjects extends StagedTypedItemMigration
{
    use ComponentSubjectMigration;

    public const PHASE = '20261014_power_supply_subjects';

    private static ?array $snapshot = null;

    private static function snapshot(): array
    {
        return self::$snapshot ??= json_decode(file_get_contents(__DIR__ . '/history/20261014-power-supply-subjects.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function unsupportedKindGuidance(): string
    {
        return 'PowerSupply core adoption supports Computer, NetworkEquipment and Enclosure subjects or unassigned stock with a blank/null kind and zero/null identity. A valid plugin subject may require a separately reviewed owning mapping and migration; retain its original source row and plugin schema. Unsupported kinds are not orphan proof, and the canonical importer cannot bypass this data-preserving refusal.';
    }
}
