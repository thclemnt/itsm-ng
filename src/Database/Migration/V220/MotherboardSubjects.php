<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use itsmng\Database\Migration\Ledger;

/** Frozen Motherboard ownership reuses the immutable 20261012 DDL and retry producer. */
final class MotherboardSubjects extends StagedTypedItemMigration
{
    public const PHASE = '20261013_motherboard_subjects';

    private static ?array $snapshot = null;

    private static function snapshot(): array
    {
        return self::$snapshot ??= json_decode(file_get_contents(__DIR__ . '/history/20261013-motherboard-subjects.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Audit local payload/owners even when older boolean and reference receipts are complete. */
    public function plan(Connection $connection, ?IncomingProjectionReferences $incomingReferences = null): array
    {
        if ((Ledger::state($connection, self::PHASE)['complete'] ?? false) === true) {
            return [];
        }
        $inspection = $connection->createSchemaManager()->introspectTable(self::snapshot()['table']);
        $shape = ComponentData::planInspectedTable($connection, self::snapshot(), $inspection);
        $plan = parent::planInspectedTable($connection, $inspection, $incomingReferences);
        $table = self::snapshot()['table'];
        $plan[$table]['columns'] = array_merge($shape, $plan[$table]['columns']);
        $plan[$table]['copy'] = array_merge(ComponentData::normalization($connection, self::snapshot()), $plan[$table]['copy']);
        return $plan;
    }

    protected function phase(): string
    {
        return self::PHASE;
    }

    protected function table(): string
    {
        return self::snapshot()['table'];
    }

    protected static function targets(): array
    {
        return array_map(static fn (array $target): string => $target['producer_target'], self::snapshot()['targets']);
    }

    protected static function allowsEmptyReference(): bool
    {
        return self::snapshot()['allows_empty_reference'];
    }

    protected static function minimumId(string $kind): int
    {
        return self::snapshot()['minimum_selected_id'];
    }

    protected function unsupportedKindGuidance(): string
    {
        return 'Motherboard assignments require a persisted declared asset or unassigned stock with a blank/null kind and zero/null identity. Resolve unsupported source kinds before adoption; the canonical importer cannot bypass this preflight.';
    }

    protected static function discriminatorSql(?AbstractPlatform $platform = null, string $alias = ''): string
    {
        $column = $alias . 'itemtype';
        return $platform instanceof AbstractMySQLPlatform ? 'CAST(' . $column . ' AS BINARY)' : $column;
    }

    /** Historical CASE shape is frozen; runtime ownership is declared on entity properties. */
    protected static function identity(string $alias = '', ?AbstractPlatform $platform = null): string
    {
        $branches = [];
        foreach (static::targets() as $kind => $target) {
            $branches[] = "WHEN " . static::discriminatorSql($platform, $alias) . " IN ('" . $kind . "') THEN " . $alias . static::column($target);
        }
        return 'CASE ' . implode(' ', $branches) . ' ELSE 0 END';
    }
}
