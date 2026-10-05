<?php

/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

namespace tests\units;

use DbTestCase;

class Item_DeviceGeneric extends DbTestCase
{
    public function componentFamilies(): array
    {
        $families = [];
        foreach (\itsmng\Database\ForeignKeys::relations() as $table => $relations) {
            if (!str_starts_with($table, 'glpi_items_device')) {
                continue;
            }
            $linkType = getItemTypeForTable($table);
            $column = $linkType::getDeviceForeignKey();
            $families[$linkType] = [$linkType, getItemTypeForTable($relations[$column]), $column];
        }
        return $families;
    }

    public function testEveryMappedComponentDeviceFamilyIsCovered(): void
    {
        $this->array($this->componentFamilies())->hasSize(17);
    }

    /**
     * @dataProvider componentFamilies
     */
    public function testComponentScopeStockReplacementAndPurge(string $linkType, string $deviceType, string $column): void
    {
        global $DB;

        $savedSession = $_SESSION;
        $savedReporting = error_reporting();
        // These public legacy entrypoints retain their documented deprecation.
        error_reporting($savedReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        try {
            $this->login();
            $this->setEntity(0, true);
            // Atoum provider rows share the outer DbTestCase transaction. Each
            // family owns fresh fixtures rather than reusing another row's graph.
            $prefix = $linkType . '-' . $this->getUniqueString();
            $asset = $this->createItem('Computer', ['name' => $prefix . '-asset', 'entities_id' => 0]);
            $foreignEntity = $this->createItem('Entity', ['name' => $prefix . '-entity', 'entities_id' => 0]);
            $other = $this->createItem('Computer', ['name' => $prefix . '-foreign', 'entities_id' => (int)$foreignEntity->getID()]);
            $device = $this->createItem($deviceType, ['designation' => $prefix . '-device', 'entities_id' => 0]);
            $replacement = $this->createItem($deviceType, ['designation' => $prefix . '-replacement', 'entities_id' => 0]);
            $deviceId = (int)$device->getID();
            $replacementId = (int)$replacement->getID();
            $assetId = (int)$asset->getID();
            $this->boolean($device->can($deviceId, UPDATE))->isTrue();
            $this->boolean($replacement->can($replacementId, UPDATE))->isTrue();
            $this->boolean($asset->can($assetId, READ))->isTrue();
            $assigned = $this->createItem($linkType, [$column => $deviceId, 'itemtype' => 'Computer', 'items_id' => $assetId, 'entities_id' => 0]);
            $foreign = $this->createItem($linkType, [$column => $deviceId, 'itemtype' => 'Computer', 'items_id' => (int)$other->getID(), 'entities_id' => 0]);
            $stock = $this->createItem($linkType, [$column => $deviceId, 'itemtype' => '', 'items_id' => 0, 'entities_id' => 0]);
            $deleted = $this->createItem($linkType, [$column => $deviceId, 'itemtype' => 'Computer', 'items_id' => $assetId, 'is_deleted' => true, 'entities_id' => 0]);
            $unrelated = $this->createItem($linkType, [$column => $replacementId, 'itemtype' => '', 'items_id' => 0, 'entities_id' => 0]);
            $link = new $linkType();
            $assignedId = (int)$assigned->getID();
            $stockId = (int)$stock->getID();

            $_SESSION['glpishowallentities'] = false;
            $_SESSION['glpiactiveentities'] = [0];
            $this->array(array_column($link->getTableGroupRows($device, 'Computer'), 'id'))->isIdenticalTo([$assignedId]);
            $this->array(array_column($link->getTableGroupRows($asset), 'id'))->isIdenticalTo([$assignedId]);
            $this->array(array_column($link->getTableGroupRows($device, ''), 'id'))->isIdenticalTo([$stockId]);
            $_SESSION['glpiactiveentities'] = [];
            $this->array($link->getTableGroupRows($device, 'Computer'))->isEmpty();
            $this->array(array_column($link->getTableGroupRows($device, ''), 'id'))->isIdenticalTo([$stockId]);
            $_SESSION['glpishowallentities'] = true;
            $this->array($link->getTableGroupRows($device, 'Computer'))->isEmpty('Explicit empty grants override the cached all-entities flag');
            $this->array(array_column($link->getTableGroupRows($device, ''), 'id'))->isIdenticalTo([$stockId]);
            $_SESSION['glpishowallentities'] = false;
            unset($_SESSION['glpiactiveentities']);
            $this->array(array_column($link->getTableGroupRows($device, 'Computer'), 'id'))->isIdenticalTo([$assignedId]);
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpishowallentities'] = true;
            $this->array(array_column($link->getTableGroupRows($device, 'Computer'), 'id'))->isIdenticalTo([$assignedId, (int)$foreign->getID()]);
            $_SESSION['glpishowallentities'] = false;

            $em = \itsmng\Database\Orm::create($DB);
            try {
                $repository = new \itsmng\Database\Repository\ComponentRepository($em);
                $this->integer($repository->detach($link->getTable(), 'Monitor', $assetId))->isIdenticalTo(0);
                $this->integer($repository->detach($link->getTable(), 'Computer', $assetId))->isIdenticalTo(2);
            } finally {
                $em->clear();
            }
            $typedStock = isset(\itsmng\Database\EntityRegistry::discriminatedReferences($link->getTable())['items_id']['empty_value']);
            $this->boolean($link->getFromDB($assignedId))->isTrue();
            $this->variable($link->fields['itemtype'])->isIdenticalTo($typedStock ? null : '');
            $this->integer((int)$link->fields['items_id'])->isIdenticalTo(0);
            $this->integer((int)$link->fields[$column])->isIdenticalTo($deviceId);
            $this->boolean($deleted->getFromDB($deleted->getID()))->isTrue();
            $this->boolean((bool)$deleted->fields['is_deleted'])->isTrue();

            $this->boolean($device->delete(['id' => $deviceId, '_replace_by' => $replacementId], true))->isTrue();
            $this->boolean($link->getFromDB($assignedId))->isTrue();
            $this->integer((int)$link->fields[$column])->isIdenticalTo($replacementId);
            $this->boolean($link->getFromDB($foreign->getID()))->isTrue();
            $this->boolean($link->getFromDB($unrelated->getID()))->isTrue();
            $this->boolean($replacement->delete(['id' => $replacementId], true))->isTrue();
            $this->array($link->find([$column => $replacementId]))->isEmpty('Device purge removes assigned, deleted and stock bindings');
        } finally {
            $_SESSION = $savedSession;
            error_reporting($savedReporting);
        }
    }

    public function testAdditionalItemDeviceLinksCrud()
    {
        $previous_error_reporting = error_reporting();
        error_reporting($previous_error_reporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $this->login();

        $computer = getItemByTypeName('Computer', '_test_pc01');
        $this->object($computer)->isInstanceOf('\Computer');

        $link_types = [
            'Item_DeviceProcessor',
            'Item_DeviceHardDrive',
            'Item_DeviceNetworkCard',
            'Item_DeviceBattery',
            'Item_DeviceControl',
            'Item_DeviceFirmware',
            'Item_DeviceGraphicCard',
            'Item_DevicePowerSupply',
            'Item_DeviceSoundCard',
            'Item_DeviceMotherboard',
            'Item_DeviceCase',
            'Item_DeviceDrive',
            'Item_DeviceGeneric',
        ];

        try {
            foreach ($link_types as $link_type) {
                $link_class = '\\' . $link_type;
                $link = new $link_class();
                $device_type = $link_type::getDeviceType();
                $device_fk = $link_type::getDeviceForeignKey();
                $device_class = '\\' . $device_type;
                $device = new $device_class();
                $device_id = $device->add([
                    'designation' => strtolower($device_type) . '-' . $this->getUniqueString(),
                ]);

                $this->integer((int)$device_id)->isGreaterThan(0);

                $id = $link->add([
                    'itemtype'    => 'Computer',
                    'items_id'    => $computer->getID(),
                    $device_fk    => $device_id,
                    'entities_id' => 0,
                ]);
                $this->integer((int)$id)->isGreaterThan(0);
                $this->boolean($link->getFromDB($id))->isTrue();

                $this->boolean($link->delete(['id' => $id]))->isTrue();
            }
        } finally {
            error_reporting($previous_error_reporting);
        }
    }

    public function testAddDevicesFromPOSTAndUpdateAll()
    {
        $previous_error_reporting = error_reporting();
        error_reporting($previous_error_reporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $this->login();

        try {
            $source_computer = getItemByTypeName('Computer', '_test_pc01');
            $target_computer = getItemByTypeName('Computer', '_test_pc02');
            $this->object($source_computer)->isInstanceOf('\Computer');
            $this->object($target_computer)->isInstanceOf('\Computer');

            $device = new \DeviceMemory();
            $device_id = $device->add([
                'designation'  => 'memory-' . $this->getUniqueString(),
                'size_default' => 2048,
            ]);
            $this->integer((int)$device_id)->isGreaterThan(0);

            $link = new \Item_DeviceMemory();
            $initial_link_id = $link->add([
                'itemtype'          => 'Computer',
                'items_id'          => $source_computer->getID(),
                'devicememories_id' => $device_id,
                'entities_id'       => 0,
            ]);
            $this->integer((int)$initial_link_id)->isGreaterThan(0);

            $link_selection_key = \Item_DeviceMemory::getForeignKeyField();
            $_POST = ['devices_id' => $device_id];
            \Item_Devices::addDevicesFromPOST([
                'devicetype'          => 'DeviceMemory',
                'itemtype'            => 'Computer',
                'items_id'            => $target_computer->getID(),
                $link_selection_key   => [$initial_link_id],
            ]);

            $this->boolean($link->getFromDB($initial_link_id))->isTrue();
            $this->integer((int)$link->getField('items_id'))->isEqualTo((int)$target_computer->getID());

            $count_before_update = count($link->find([
                'itemtype'          => 'Computer',
                'items_id'          => $target_computer->getID(),
                'devicememories_id' => $device_id,
                'is_deleted'        => 0,
            ]));

            $_POST = [
                'itemtype'                                      => 'Computer',
                'items_id'                                      => $target_computer->getID(),
                'value_DeviceMemory_' . $initial_link_id . '_size' => 8192,
            ];
            \Item_Devices::updateAll($_POST);
            $_POST = [];

            $links_after_update = $link->find([
                'itemtype'          => 'Computer',
                'items_id'          => $target_computer->getID(),
                'devicememories_id' => $device_id,
                'is_deleted'        => 0,
            ]);
            $this->integer(count($links_after_update))->isEqualTo($count_before_update);

            $this->boolean($link->getFromDB($initial_link_id))->isTrue();
            $this->integer((int)$link->getField('size'))->isEqualTo(8192);
        } finally {
            $_POST = [];
            error_reporting($previous_error_reporting);
        }
    }
}
