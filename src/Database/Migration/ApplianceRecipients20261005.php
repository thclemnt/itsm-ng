<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen 20261005 appliance relationship upgrade; historical targets never follow current metadata. */
final class ApplianceRecipients20261005 extends StagedTypedItemMigration
{
    public const VERSION = '20261005_appliance_recipients';

    protected function version(): string
    {
        return self::VERSION;
    }

    protected function table(): string
    {
        return 'glpi_appliances_items_relations';
    }

    protected function unsupportedKindGuidance(): string
    {
        return 'Resolve these links before adoption. Legacy appliance plugin import requires a compatible historical application and legacy MySQL schema before switching to modernized source and db:migrate. A canonical ORM importer requires completed migration history and cannot be used to bypass this legacy-data preflight.';
    }

    protected static function targets(): array
    {
        return [
            'Location' => 'locations',
            'Network' => 'networks',
            'Domain' => 'domains',
        ];
    }
}
