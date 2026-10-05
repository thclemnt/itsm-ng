<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen 20261001 upgrade for assigned and returned consumable stock. */
final class ConsumableRecipients extends TypedItemMigration
{
    protected function tables(): array
    {
        return ['glpi_consumables'];
    }

    protected static function targets(): array
    {
        return ['User' => 'users', 'Group' => 'groups'];
    }

    protected static function allowsEmptyReference(): bool
    {
        return true;
    }

    protected static function emptyReferenceSql(string $alias = '', ?\Doctrine\DBAL\Platforms\AbstractPlatform $platform = null): string
    {
        return parent::emptyReferenceSql($alias) . ' AND ' . $alias . 'date_out IS NULL';
    }
}
