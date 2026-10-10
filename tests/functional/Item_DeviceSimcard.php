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
use Computer;
use DeviceSimcard;
use Item_DeviceSimcard as SimcardLink;

class Item_DeviceSimcard extends DbTestCase
{
    public function testCreate()
    {
        $this->login();
        $obj = new \Item_DeviceSimcard();

        // Add
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $this->object($computer)->isInstanceOf('\Computer');
        $deviceSimcard = getItemByTypeName('DeviceSimcard', '_test_simcard_1');
        $this->object($deviceSimcard)->isInstanceOf('\DeviceSimcard');
        $in = [
              'itemtype'           => 'Computer',
              'items_id'           => $computer->getID(),
              'devicesimcards_id'  => $deviceSimcard->getID(),
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
        $obj = new \Item_DeviceSimcard();

        // Add
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $this->object($computer)->isInstanceOf('\Computer');
        $deviceSimcard = getItemByTypeName('DeviceSimcard', '_test_simcard_1');
        $this->object($deviceSimcard)->isInstanceOf('\DeviceSimcard');
        $id = $obj->add([
              'itemtype'           => 'Computer',
              'items_id'           => $computer->getID(),
              'devicesimcards_id'  => $deviceSimcard->getID(),
              'entities_id'        => 0,
        ]);
        $this->integer($id)->isGreaterThan(0);

        // Update
        $id = $obj->getID();
        $in = [
              'id'                       => $id,
              'pin'                      => '0123',
              'pin2'                     => '1234',
              'puk'                      => '2345',
              'puk2'                     => '3456',
        ];
        $this->boolean($obj->update($in))->isTrue();
        $this->boolean($obj->getFromDB($id))->isTrue();

        // getField methods
        foreach ($in as $k => $v) {
            $this->variable($obj->getField($k))->isEqualTo($v);
        }
    }

    public function testDenyPinPukUpdate()
    {
        global $DB;
        //drop update access on item_devicesimcard
        $DB->update(
            'glpi_profilerights',
            ['rights' => 1],
            [
              'profiles_id'  => 4,
              'name'         => 'devicesimcard_pinpuk'
         ]
        );

        // Profile changed then login
        $this->login();
        //reset rights. Done here so ACLs are reset even if tests fails.
        $DB->update(
            'glpi_profilerights',
            ['rights' => 3],
            [
              'profiles_id'  => 4,
              'name'         => 'devicesimcard_pinpuk'
         ]
        );

        $obj = new \Item_DeviceSimcard();

        // Add
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $this->object($computer)->isInstanceOf('\Computer');
        $deviceSimcard = getItemByTypeName('DeviceSimcard', '_test_simcard_1');
        $this->object($deviceSimcard)->isInstanceOf('\DeviceSimcard');
        $id = $obj->add([
              'itemtype'           => 'Computer',
              'items_id'           => $computer->getID(),
              'devicesimcards_id'  => $deviceSimcard->getID(),
              'entities_id'        => 0,
              'pin'                => '0123',
              'pin2'               => '1234',
              'puk'                => '2345',
              'puk2'               => '3456',
        ]);
        $this->integer($id)->isGreaterThan(0);

        // Update
        $id = $obj->getID();
        $in = [
              'id'                 => $id,
              'pin'                => '0000',
              'pin2'               => '0000',
              'puk'                => '0000',
              'puk2'               => '0000',
        ];
        $this->boolean($obj->update($in))->isTrue();
        $this->boolean($obj->getFromDB($id))->isTrue();

        // getField methods
        unset($in['id']);
        foreach ($in as $k => $v) {
            $this->variable($obj->getField($k))->isNotEqualTo($v);
        }
    }


    public function testDelete()
    {
        $this->login();
        $obj = new \Item_DeviceSimcard();

        // Add
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $this->object($computer)->isInstanceOf('\Computer');
        $deviceSimcard = getItemByTypeName('DeviceSimcard', '_test_simcard_1');
        $this->object($deviceSimcard)->isInstanceOf('\DeviceSimcard');
        $id = $obj->add([
              'itemtype'           => 'Computer',
              'items_id'           => $computer->getID(),
              'devicesimcards_id'  => $deviceSimcard->getID(),
              'entities_id'        => 0,
        ]);
        $this->integer($id)->isGreaterThan(0);

        // Delete
        $in = [
              'id'                       => $obj->getID(),
        ];
        $this->boolean($obj->delete($in))->isTrue();
    }

    public function testProtectedPinUpdateRetainsOwningParentRetargetCloneAndPurge(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $session = $_SESSION;
        $priorRights = $connection->fetchOne("SELECT rights FROM glpi_profilerights WHERE profiles_id=4 AND name='devicesimcard_pinpuk'");
        try {
            $connection->update('glpi_profilerights', ['rights' => READ | UPDATE], ['profiles_id' => 4, 'name' => 'devicesimcard_pinpuk']);
            $this->login();
            $this->setEntity(0, true);
            $prefix = $this->getUniqueString();
            $first = $this->createItem(Computer::class, ['name' => $prefix, 'entities_id' => 0]);
            $second = $this->createItem(Computer::class, ['name' => $prefix . '-second', 'entities_id' => 0]);
            $device = $this->createItem(DeviceSimcard::class, ['designation' => $prefix, 'entities_id' => 0]);
            $row = $this->createItem(SimcardLink::class, ['devicesimcards_id' => $device->getID(), 'itemtype' => 'Computer',
                'items_id' => $first->getID(), 'entities_id' => 0, 'pin' => '0123', 'pin2' => '1234', 'puk' => '2345', 'puk2' => '3456']);
            $original = array_intersect_key($row->fields, array_fill_keys(['pin', 'pin2', 'puk', 'puk2'], true));
            $this->array($original)->isIdenticalTo(['pin' => '0123', 'pin2' => '1234', 'puk' => '2345', 'puk2' => '3456']);
            $connection->update('glpi_profilerights', ['rights' => READ], ['profiles_id' => 4, 'name' => 'devicesimcard_pinpuk']);
            $this->login();
            $this->setEntity(0, true);
            $this->boolean($row->update(['id' => $row->getID(), 'items_id' => $second->getID(),
                'pin' => '0000', 'pin2' => '0000', 'puk' => '0000', 'puk2' => '0000']))->isTrue();
            $this->boolean($row->getFromDB($row->getID()))->isTrue();
            $this->array(array_intersect_key($row->fields, $original))->isIdenticalTo($original);
            $this->integer((int)$row->fields['computers_id'])->isIdenticalTo((int)$second->getID());
            $this->variable($row->fields['opaque_parent_id'])->isNull();
            $connection->update('glpi_profilerights', ['rights' => READ | UPDATE], ['profiles_id' => 4, 'name' => 'devicesimcard_pinpuk']);
            $this->login();
            $this->setEntity(0, true);
            $cloned = (int)$second->clone(['name' => $prefix . '-clone']);
            $this->integer($cloned)->isGreaterThan(0);
            $children = $row->find(['itemtype' => 'Computer', 'items_id' => $cloned]);
            $this->array($children)->hasSize(1);
            $child = new SimcardLink();
            $this->boolean($child->getFromDB(array_key_first($children)))->isTrue();
            $this->array(array_intersect_key($child->fields, $original))->isIdenticalTo($original);
            $this->integer((int)$child->fields['computers_id'])->isIdenticalTo($cloned);
            $clone = new Computer();
            $this->boolean($clone->getFromDB($cloned))->isTrue();
            $this->boolean($clone->delete(['id' => $cloned], true))->isTrue();
            $this->boolean($child->getFromDB($child->getID()))->isFalse();
            $this->boolean($row->getFromDB($row->getID()))->isTrue();
            $this->array(array_intersect_key($row->fields, $original))->isIdenticalTo($original);
        } finally {
            $connection->update('glpi_profilerights', ['rights' => $priorRights], ['profiles_id' => 4, 'name' => 'devicesimcard_pinpuk']);
            $_SESSION = $session;
        }
    }

}
