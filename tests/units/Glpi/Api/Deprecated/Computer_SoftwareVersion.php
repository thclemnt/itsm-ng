<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\Glpi\Api\Deprecated;

use GLPITestCase;
use Glpi\Api\Deprecated\Computer_SoftwareLicense;
use Glpi\Api\Deprecated\Computer_SoftwareVersion as DeprecatedComputer_SoftwareVersion;

class Computer_SoftwareVersion extends GLPITestCase
{
    public function testSoftwareProjectionsPreserveTheDeprecatedFields(): void
    {
        $projections = [
            DeprecatedComputer_SoftwareVersion::class => [
                'id' => 11, 'computers_id' => 12, 'softwareversions_id' => 13,
                'is_deleted_computer' => false, 'is_template_computer' => false,
                'entities_id' => 0, 'is_deleted' => false, 'is_dynamic' => false,
                'date_install' => null, 'links' => [],
            ],
            Computer_SoftwareLicense::class => [
                'id' => 11, 'computers_id' => 12, 'softwarelicenses_id' => 13,
                'is_deleted' => false, 'is_dynamic' => false, 'links' => [],
            ],
        ];
        foreach ($projections as $type => $expected) {
            $current = $expected;
            unset($current['is_deleted_computer'], $current['is_template_computer']);
            $current += ['itemtype' => 'Computer', 'items_id' => 12];
            if (isset($expected['softwareversions_id'])) {
                $current += ['is_deleted_item' => false, 'is_template_item' => false];
            }
            $mapper = new $type();
            foreach ([null, 0, 99] as $owner) {
                $fields = $current + [
                    'monitors_id' => $owner, 'networkequipments_id' => $owner,
                    'peripherals_id' => $owner, 'phones_id' => $owner, 'printers_id' => $owner,
                ];
                $this->array($mapper->mapCurrentToDeprecatedFields($fields))
                    ->isEqualTo($expected)->hasSize(count($expected));
                $this->array($fields)->hasKeys(['monitors_id', 'networkequipments_id', 'peripherals_id', 'phones_id', 'printers_id']);

            }
            $this->array($mapper->mapCurrentToDeprecatedFields($current))->isEqualTo($expected);
        }
    }

    public function testDeprecatedCreationMapsTheJsonObjectInput(): void
    {
        $version = new DeprecatedComputer_SoftwareVersion();
        $input = (object)[
            'computers_id' => 12, 'softwareversions_id' => 13,
            'is_template_computer' => false, 'is_deleted_computer' => false,
        ];
        $this->array((array)$version->mapDeprecatedToCurrentFields($input))->isEqualTo([
            'items_id' => 12, 'softwareversions_id' => 13, 'itemtype' => 'Computer',
            'is_template_item' => false, 'is_deleted_item' => false,
        ]);

        $license = new Computer_SoftwareLicense();
        $input = (object)['computers_id' => 12, 'softwarelicenses_id' => 13];
        $this->array((array)$license->mapDeprecatedToCurrentFields($input))->isEqualTo([
            'items_id' => 12, 'softwarelicenses_id' => 13, 'itemtype' => 'Computer',
        ]);
    }
}
