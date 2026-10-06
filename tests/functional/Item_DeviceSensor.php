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

class Item_DeviceSensor extends DbTestCase
{
    public function testTypedSubjectsPreserveStockCloneAndScope(): void
    {
        global $DB;
        $savedSession = $_SESSION;
        try {
            $this->login();
            $this->setEntity(0, true);
            $name = 'sensor-' . $this->getUniqueString();
            $definition = $this->createItem('DeviceSensor', ['designation' => $name, 'entities_id' => 0, 'is_recursive' => 1]);
            $computer = $this->createItem('Computer', ['name' => $name, 'entities_id' => 0]);
            $peripheral = $this->createItem('Peripheral', ['name' => $name, 'entities_id' => 0]);
            $links = [];
            foreach (['Computer' => $computer, 'Peripheral' => $peripheral] as $kind => $asset) {
                $link = $this->createItem('Item_DeviceSensor', ['devicesensors_id' => (int)$definition->getID(),
                    'itemtype' => $kind, 'items_id' => (int)$asset->getID(), 'serial' => $name]);
                $this->boolean($link->getFromDB($link->getID()))->isTrue();
                $column = $kind === 'Computer' ? 'computers_id' : 'peripherals_id';
                $this->integer((int)$link->fields[$column])->isIdenticalTo((int)$asset->getID());
                $this->integer((int)$link->fields['items_id'])->isIdenticalTo((int)$asset->getID());
                $this->integer((int)$link->fields['entities_id'])->isIdenticalTo(0);
                $this->integer((int)$link->fields['is_recursive'])->isIdenticalTo(1, 'Assignment scope is inherited from the definition');
                $links[$kind] = $link;
            }
            $repository = new \itsmng\Database\Repository\ComponentRepository(\itsmng\Database\Orm::create($DB));
            $arguments = ['glpi_items_devicesensors', 'devicesensors_id', (int)$definition->getID(), 'Computer', 'glpi_computers'];
            $this->array($repository->forDevice(...[...$arguments, []]))->isEmpty();
            $this->array($repository->forDevice(...[...$arguments, [0]]))->hasSize(1);
            $this->array($repository->forDevice(...[...$arguments, null]))->hasSize(1);
            $this->boolean($computer->getFromDB($computer->getID()))->isTrue();
            $destinationId = $computer->clone(['name' => $name . '-clone']);
            $this->integer($destinationId)->isGreaterThan(0)->isNotEqualTo((int)$computer->getID());
            $destination = new \Computer();
            $this->boolean($destination->getFromDB($destinationId))->isTrue();
            $copies = $links['Computer']->find(['itemtype' => 'Computer', 'items_id' => $destination->getID()]);
            $this->array($copies)->hasSize(1);
            $copy = reset($copies);
            $this->integer((int)$copy['computers_id'])->isIdenticalTo((int)$destination->getID());
            $this->string($copy['serial'])->isIdenticalTo($name);
            \Item_Devices::cleanItemDeviceDBOnItemDelete('Computer', (int)$computer->getID(), true);
            $this->boolean($links['Computer']->getFromDB($links['Computer']->getID()))->isTrue();
            $this->variable($links['Computer']->fields['itemtype'])->isNull();
            $this->variable($links['Computer']->fields['computers_id'])->isNull();
            $this->integer((int)$links['Computer']->fields['items_id'])->isIdenticalTo(0);
            $this->string($links['Computer']->fields['serial'])->isIdenticalTo($name);
            \Item_Devices::cleanItemDeviceDBOnItemDelete('Computer', (int)$destination->getID(), false);
            $this->array($links['Computer']->find(['itemtype' => 'Computer', 'items_id' => $destination->getID()]))->isEmpty();
            $this->boolean($links['Peripheral']->getFromDB($links['Peripheral']->getID()))->isTrue();
            $this->array($repository->forDevice('glpi_items_devicesensors', 'devicesensors_id', (int)$definition->getID(), '', null, null))->hasSize(1);
            $entity = new \itsmng\Database\Entity\ItemDeviceSensor();
            foreach ([['itemtype' => 'computer', 'items_id' => $computer->getID()],
                ['itemtype' => 'Computer', 'items_id' => 0],
                ['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'peripherals_id' => $peripheral->getID()]] as $invalid) {
                $this->exception(static fn () => $entity->normalizeInput($invalid))->isInstanceOf(\InvalidArgumentException::class);
            }
            $this->boolean($repository->hasSelectedSubject('glpi_items_devicesensors', ['itemtype' => 'Computer', 'computers_id' => PHP_INT_MAX]))->isFalse();
            $stock = $entity->normalizeInput(['itemtype' => '', 'items_id' => 0]);
            $this->variable($stock['itemtype'])->isNull();
            $this->variable($stock['computers_id'])->isNull();
            $this->variable($stock['peripherals_id'])->isNull();
            $this->boolean(array_key_exists('items_id', $stock))->isFalse();
        } finally {
            $_SESSION = $savedSession;
        }
    }

    public function testCreate()
    {
        $this->login();
        $obj = new \Item_DeviceSensor();

        // Add
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $this->object($computer)->isInstanceOf('\Computer');
        $deviceSensor = getItemByTypeName('DeviceSensor', '_test_sensor_1');
        $this->object($deviceSensor)->isInstanceOf('\DeviceSensor');
        $in = [
              'itemtype'           => 'Computer',
              'items_id'           => $computer->getID(),
              'devicesensors_id'  => $deviceSensor->getID(),
              'entities_id'        => 0,
        ];
        $id = $obj->add($in);
        $this->integer((int)$id)->isGreaterThan(0);
        $this->boolean($obj->getFromDB($id))->isTrue();

        // getField methods
        $this->variable($obj->getField('id'))->isEqualTo($id);
        foreach ($in as $k => $v) {
            $this->variable($obj->getField($k))->isEqualTo($v);
        }
    }

    public function testUpdate()
    {
        $this->login();
        $obj = new \Item_DeviceSensor();

        // Add
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $this->object($computer)->isInstanceOf('\Computer');
        $deviceSensor = getItemByTypeName('DeviceSensor', '_test_sensor_1');
        $this->object($deviceSensor)->isInstanceOf('\DeviceSensor');
        $id = $obj->add([
              'itemtype'           => 'Computer',
              'items_id'           => $computer->getID(),
              'devicesensors_id'   => $deviceSensor->getID(),
              'entities_id'        => 0,
        ]);
        $this->integer($id)->isGreaterThan(0);

        // Update
        $id = $obj->getID();
        $in = [
              'id'                       => $id,
              'serial'                   => $this->getUniqueString(),
        ];
        $this->boolean($obj->update($in))->isTrue();
        $this->boolean($obj->getFromDB($id))->isTrue();

        // getField methods
        foreach ($in as $k => $v) {
            $this->variable($obj->getField($k))->isEqualTo($v);
        }
    }

    public function testDelete()
    {
        $this->login();
        $obj = new \Item_DeviceSensor();

        // Add
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $this->object($computer)->isInstanceOf('\Computer');
        $deviceSensor = getItemByTypeName('DeviceSensor', '_test_sensor_1');
        $this->object($deviceSensor)->isInstanceOf('\DeviceSensor');
        $id = $obj->add([
              'itemtype'           => 'Computer',
              'items_id'           => $computer->getID(),
              'devicesensors_id'   => $deviceSensor->getID(),
              'entities_id'        => 0,
        ]);
        $this->integer($id)->isGreaterThan(0);

        // Delete
        $in = [
              'id'                       => $obj->getID(),
        ];
        $this->boolean($obj->delete($in))->isTrue();
    }

}
