<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/** Frozen Computer-or-stock ownership; device, entity and inventory payload remain independent. */
final class ProcessorSubjects20261012 extends ProcessorStagedTypedItemMigration20261012
{
    public const VERSION = '20261012_processor_subjects';

    private static ?array $snapshot = null;

    private static function snapshot(): array
    {
        return self::$snapshot ??= json_decode(file_get_contents(__DIR__ . '/history/20261012-processor-subjects.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function version(): string
    {
        return self::VERSION;
    }

    protected function table(): string
    {
        return self::snapshot()['table'];
    }

    protected function unsupportedKindGuidance(): string
    {
        return 'Processor assignments require a persisted Computer or unassigned stock with a blank/null kind and zero/null legacy identity. Resolve unsupported source kinds using the compatible historical application before adoption; a canonical importer cannot bypass this preflight.';
    }

    protected static function targets(): array
    {
        return self::snapshot()['targets'];
    }

    protected static function allowsEmptyReference(): bool
    {
        return self::snapshot()['allows_empty_reference'];
    }

    protected static function minimumId(string $kind): int
    {
        return self::snapshot()['minimum_selected_id'];
    }

    /** Exactness is frozen here; current runtime exactness remains property-owned. */
    protected static function discriminatorSql(?AbstractPlatform $platform = null, string $alias = ''): string
    {
        $column = $alias . 'itemtype';
        return $platform instanceof AbstractMySQLPlatform ? 'CAST(' . $column . ' AS BINARY)' : $column;
    }

    /** Frozen shape matches the owning projection; older declarations stay unchanged. */
    protected static function identity(string $alias = '', ?AbstractPlatform $platform = null): string
    {
        return "CASE WHEN " . static::discriminatorSql($platform, $alias) . " IN ('Computer') THEN " . $alias . "computers_id ELSE 0 END";
    }
}
