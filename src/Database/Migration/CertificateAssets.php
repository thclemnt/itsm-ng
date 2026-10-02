<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen 20261001 upgrade of configured certificate assets. */
final class CertificateAssets extends TypedItemMigration
{
    public const VERSION = '20261001_certificate_assets';

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
