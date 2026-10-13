<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen 20261001 upgrade of configured certificate assets. */
final class CertificateAssets extends TypedItemMigration
{
    protected function tables(): array
    {
        return ['glpi_certificates_items'];
    }

    protected static function targets(): array
    {
        return [
            'Computer' => 'computers',
            'NetworkEquipment' => 'networkequipments',
            'Peripheral' => 'peripherals',
            'Phone' => 'phones',
            'Printer' => 'printers',
            'SoftwareLicense' => 'softwarelicenses',
            'User' => 'users',
            'Domain' => 'domains',
            'Appliance' => 'appliances',
        ];
    }
}
