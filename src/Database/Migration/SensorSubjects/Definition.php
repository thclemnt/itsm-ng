<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\SensorSubjects;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use itsmng\Database\Migration\V220\StagedTypedItemMigration;

/** Frozen Sensor declaration; reuse the immutable DDL/retry engine, never current metadata. */
final class Definition extends StagedTypedItemMigration
{
    // History owns the outer completion receipt; this checkpoint retains native
    // projection/CHECK proof across nontransactional MySQL DDL interruptions.
    public const PHASE = 'schema.sensor_subjects.v1.ddl';

    protected function phase(): string
    {
        return self::PHASE;
    }

    protected function table(): string
    {
        return 'glpi_items_devicesensors';
    }

    protected static function targets(): array
    {
        return ['Computer' => 'computers', 'Peripheral' => 'peripherals'];
    }

    protected static function allowsEmptyReference(): bool
    {
        return true;
    }

    protected static function discriminatorSql(?AbstractPlatform $platform = null, string $alias = ''): string
    {
        $column = $alias . 'itemtype';
        return $platform instanceof AbstractMySQLPlatform ? 'CAST(' . $column . ' AS BINARY)' : $column;
    }

    protected static function identity(string $alias = '', ?AbstractPlatform $platform = null): string
    {
        $branches = [];
        foreach (self::targets() as $kind => $target) {
            $branches[] = "WHEN " . self::discriminatorSql($platform, $alias) . " IN ('" . $kind . "') THEN " . $alias . self::column($target);
        }
        return 'CASE ' . implode(' ', $branches) . ' ELSE 0 END';
    }

    protected function unsupportedKindGuidance(): string
    {
        return 'Sensor assignments require an existing Computer or Peripheral, or unassigned stock with blank/null kind and zero/null identity. Resolve unsupported source links before this forward schema migration; no rows were repaired.';
    }
}
