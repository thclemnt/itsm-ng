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

class Item_DeviceMemory extends DbTestCase
{
    public function testCloneStockAndDeletePreservePayloadWithoutLegacyQueries(): void
    {
        global $DB, $CFG_GLPI;

        $savedSession = $_SESSION;
        $savedConfig = $CFG_GLPI;
        $hadDebug = array_key_exists('DEBUG_SQL', $GLOBALS);
        $savedDebug = $GLOBALS['DEBUG_SQL'] ?? null;
        $hadCount = array_key_exists('SQL_TOTAL_REQUEST', $GLOBALS);
        $savedCount = $GLOBALS['SQL_TOTAL_REQUEST'] ?? null;
        global $DEBUG_SQL, $SQL_TOTAL_REQUEST;
        $savedReporting = error_reporting();
        error_reporting($savedReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        try {
            $this->login();
            $this->setEntity(0, true);
            $prefix = 'memory-clone-' . $this->getUniqueString();
            $asset = $this->createItem('Computer', ['name' => $prefix . '-source', 'entities_id' => 0]);
            $foreignEntity = $this->createItem('Entity', ['name' => $prefix . '-entity', 'entities_id' => 0]);
            $destination = $this->createItem('Computer', ['name' => $prefix . '-destination', 'entities_id' => (int)$foreignEntity->getID()]);
            $device = $this->createItem('DeviceMemory', ['designation' => $prefix, 'entities_id' => 0]);
            $assetId = (int)$asset->getID();
            $destinationId = (int)$destination->getID();
            $deviceId = (int)$device->getID();
            $this->boolean($asset->can($assetId, READ))->isTrue();
            $this->boolean($destination->can($destinationId, READ))->isTrue();
            $this->boolean($device->can($deviceId, UPDATE))->isTrue();
            $link = $this->createItem('Item_DeviceMemory', ['devicememories_id' => $deviceId, 'itemtype' => 'Computer', 'items_id' => $assetId,
                'entities_id' => 0, 'size' => 8192, 'serial' => null]);
            $id = (int)$link->getID();
            $associated = \Item_Devices::getItemsAssociatedTo('Computer', $assetId);
            $this->array($associated)->hasSize(1);
            $this->integer((int)$associated[0]->getID())->isIdenticalTo($id);

            \Item_Devices::cloneItem('Computer', $assetId, $destinationId);
            $cloned = $link->find(['itemtype' => 'Computer', 'items_id' => $destinationId]);
            $this->array($cloned)->hasSize(1);
            $copy = reset($cloned);
            $this->integer((int)$copy['size'])->isIdenticalTo(8192);
            $this->variable($copy['serial'])->isNull();
            $this->integer((int)$copy['devicememories_id'])->isIdenticalTo($deviceId);
            $reference = \itsmng\Database\EntityRegistry::discriminatedReferences($link->getTable())['items_id'];
            $this->integer((int)$copy[$reference['selections']['Computer']['column']])->isIdenticalTo($destinationId);

            \Item_Devices::cleanItemDeviceDBOnItemDelete('Computer', $assetId, true);
            $this->boolean($link->getFromDB($id))->isTrue();
            $this->array($link->fields)->hasKeys(['itemtype', 'items_id', 'serial']);
            $this->variable($link->fields['itemtype'])->isIdenticalTo($reference['empty_value'] === 0 ? null : '');
            $this->integer((int)$link->fields['items_id'])->isIdenticalTo(0);
            $this->integer((int)$link->fields['devicememories_id'])->isIdenticalTo($deviceId);
            $this->integer((int)$link->fields['size'])->isIdenticalTo(8192);
            $this->variable($link->fields['serial'])->isNull();
            \Item_Devices::cleanItemDeviceDBOnItemDelete('Computer', $destinationId, false);
            $this->array($link->find(['itemtype' => 'Computer', 'items_id' => $destinationId]))->isEmpty();
            $this->boolean($link->getFromDB($id))->isTrue('Deleting the assigned clone preserves the separate stock binding');

            $_SESSION['glpi_use_mode'] = \Session::DEBUG_MODE;
            $CFG_GLPI['debug_sql'] = true;
            $DEBUG_SQL = [];
            $SQL_TOTAL_REQUEST = 0;
            $this->array(array_column($link->getTableGroupRows($device, ''), 'id'))->isIdenticalTo([$id]);
            $link->getTableGroupRows($asset);
            $link->getTableGroupRows($device, 'Computer');
            \Item_Devices::getItemsAssociatedTo('Computer', $assetId);
            \Item_Devices::cleanItemDeviceDBOnItemDelete('Computer', $assetId, true);
            $this->integer($SQL_TOTAL_REQUEST)->isIdenticalTo(0);
            $this->array((new \itsmng\Database\ForeignKeys())->audit($DB->getDoctrineConnection()))->isEmpty();
        } finally {
            $_SESSION = $savedSession;
            $CFG_GLPI = $savedConfig;
            if ($hadDebug) {
                $DEBUG_SQL = $savedDebug;
            } else {
                unset($GLOBALS['DEBUG_SQL']);
            }
            if ($hadCount) {
                $SQL_TOTAL_REQUEST = $savedCount;
            } else {
                unset($GLOBALS['SQL_TOTAL_REQUEST']);
            }
            error_reporting($savedReporting);
        }
    }

    public function testCrud()
    {
        $this->login();

        $computer = getItemByTypeName('Computer', '_test_pc01');
        $this->object($computer)->isInstanceOf('\Computer');

        $device = new \DeviceMemory();
        $device_id = $device->add([
           'designation' => 'memory-' . $this->getUniqueString(),
        ]);
        $this->integer((int)$device_id)->isGreaterThan(0);

        $obj = new \Item_DeviceMemory();
        $id = $obj->add([
           'itemtype'          => 'Computer',
           'items_id'          => $computer->getID(),
           'devicememories_id' => $device_id,
           'entities_id'       => 0,
           'size'              => 4096,
        ]);
        $this->integer((int)$id)->isGreaterThan(0);
        $this->boolean($obj->getFromDB($id))->isTrue();
        $this->integer((int)$obj->getField('size'))->isEqualTo(4096);

        $this->boolean(
            $obj->update([
               'id'      => $id,
               'serial'  => $this->getUniqueString(),
            ])
        )->isTrue();
        $this->boolean($obj->getFromDB($id))->isTrue();

        $this->boolean($obj->delete(['id' => $id]))->isTrue();
    }
}
