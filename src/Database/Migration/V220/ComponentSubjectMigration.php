<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use itsmng\Database\Migration\Ledger;

/** Shared execution for frozen component snapshots; each owner retains its phase and input. */
trait ComponentSubjectMigration
{
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
