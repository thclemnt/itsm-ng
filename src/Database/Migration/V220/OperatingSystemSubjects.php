<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;

/** Frozen OS assignment subjects; component dropdown roles remain independent. */
final class OperatingSystemSubjects extends StagedTypedItemMigration
{
    public const PHASE = '20261006_operating_system_subjects';

    public function plan(Connection $connection, ?IncomingProjectionReferences $incomingReferences = null): array
    {
        $plan = parent::plan($connection, $incomingReferences);
        if ($plan) {
            $columns = $connection->createSchemaManager()->listTableColumns($this->table());
            (new InventoryUniqueness())->assertUniqueAssignments($connection, isset($columns['items_id']) ? 'items_id' : self::identity());
        }
        return $plan;
    }

    protected function phase(): string
    {
        return self::PHASE;
    }

    protected function table(): string
    {
        return 'glpi_items_operatingsystems';
    }

    protected function unsupportedKindGuidance(): string
    {
        return 'OS assignments require a persisted Computer, Monitor, NetworkEquipment, Peripheral, Phone or Printer. Resolve unsupported source subject links using the compatible historical application before adoption; no canonical inventory importer can bypass this preflight.';
    }

    protected static function targets(): array
    {
        return [
            'Computer' => 'computers',
            'Monitor' => 'monitors',
            'NetworkEquipment' => 'networkequipments',
            'Peripheral' => 'peripherals',
            'Phone' => 'phones',
            'Printer' => 'printers',
        ];
    }
}
