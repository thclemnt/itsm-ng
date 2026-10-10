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

use Alert;
use CartridgeItem as CartridgeModel;
use DbTestCase;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Orm;
use itsmng\Database\Repository\CartridgeRepository;

/* Test for inc/cartridge.class.php */

class Cartridge extends DbTestCase
{
    private function processMassiveAction($action, \CommonDBTM $item, array $ids, array $input = [])
    {
        $ok = 0;
        $ko = 0;

        $controller = new \atoum\atoum\mock\controller();
        $controller->__construct = function ($args) {
        };

        $ma = new \mock\MassiveAction([], [], '', false, $controller);
        $ma->getMockController()->getAction = $action;
        $ma->getMockController()->addMessage = function () {
        };
        $ma->getMockController()->getInput = $input;
        $ma->getMockController()->itemDone = function ($itemtype, $id, $res) use (&$ok, &$ko) {
            if ($res == \MassiveAction::ACTION_OK) {
                $ok++;
            } else {
                $ko++;
            }
        };

        \Cartridge::processMassiveActionsForOneItemtype($ma, $item, $ids);

        return [$ok, $ko];
    }

    public function testInstall()
    {
        $printer = new \Printer();
        $pid = $printer->add([
           'name'         => 'Test printer',
           'entities_id'  => getItemByTypeName('Entity', '_test_root_entity', true)
        ]);
        $this->integer((int)$pid)->isGreaterThan(0);
        $this->boolean($printer->getFromDB($pid))->isTrue();

        $ctype = new \CartridgeItemType();
        $tid = $ctype->add([
           'name'         => 'Test cartridge type',
        ]);
        $this->integer((int)$tid)->isGreaterThan(0);
        $this->boolean($ctype->getFromDB($tid))->isTrue();

        $citem = new \CartridgeItem();
        $ciid = $citem->add([
           'name'                  => 'Test cartridge item',
           'cartridgeitemtypes_id' => $tid
        ]);
        $this->integer((int)$ciid)->isGreaterThan(0);
        $this->boolean($citem->getFromDB($ciid))->isTrue();

        $cartridge = new \Cartridge();
        $cid = $cartridge->add([
           'name'               => 'Test cartridge',
           'cartridgeitems_id'  => $ciid
        ]);
        $this->integer((int)$cid)->isGreaterThan(0);
        $this->boolean($cartridge->getFromDB($cid))->isTrue();
        $this->integer((int)CartridgeModel::getCount($ciid))->isIdenticalTo(1);
        $this->integer($cartridge->getUsedNumber($ciid))->isIdenticalTo(0);
        $this->integer($cartridge->getTotalNumberForPrinter($pid))->isIdenticalTo(0);

        //install
        $this->boolean($cartridge->install($pid, $ciid))->isTrue();
        //check install
        $this->boolean($cartridge->getFromDB($cid))->isTrue();
        $this->array($cartridge->fields)
           ->variable['printers_id']->isEqualTo($pid)
           ->string['date_use']->matches('#\d{4}-\d{2}-\d{2}$#');
        $this->variable($cartridge->fields['date_out'])->isNull();
        //already installed
        $this->boolean($cartridge->install($pid, $ciid))->isFalse();
        $this->hasSessionMessages(ERROR, ['No free cartridge']);

        $this->integer($cartridge->getUsedNumber($ciid))->isIdenticalTo(1);
        $this->integer($cartridge->getTotalNumberForPrinter($pid))->isIdenticalTo(1);

        $this->boolean($cartridge->uninstall($cid))->isTrue();
        //this is not possible... But don't know if this is expected
        //$this->boolean($cartridge->install($pid, $ciid))->isTrue();
        //check uninstall
        $this->boolean($cartridge->getFromDB($cid))->isTrue();
        $this->string($cartridge->fields['date_out'])->matches('#\d{4}-\d{2}-\d{2}$#');
        $this->integer((int)CartridgeModel::getCount($ciid))->isIdenticalTo(1);
        $this->integer($cartridge->getUsedNumber($ciid))->isIdenticalTo(0);
    }

    public function testStockAlarmCandidateScopeAndRepeat()
    {
        global $DB;

        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $model = new CartridgeModel();
        $models = [];
        foreach (['fresh' => [], 'expired' => [], 'recent' => [],
            'disabled' => ['alarm_threshold' => -1], 'deleted' => ['is_deleted' => 1],
            'other_entity' => ['entities_id' => 0]] as $name => $extra) {
            $models[$name] = (int)$model->add($extra + [
                'name' => 'stock-alarm-' . $name . '-' . $this->getUniqueString(),
                'entities_id' => $entity,
                'alarm_threshold' => 0,
            ]);
            $this->integer($models[$name])->isGreaterThan(0);
        }
        $alert = new Alert();
        $expired = (int)$alert->add([
            'itemtype' => 'CartridgeItem', 'items_id' => $models['expired'],
            'type' => Alert::THRESHOLD, 'date' => '2000-01-01 12:00:00',
        ]);
        $this->integer($expired)->isGreaterThan(0);
        $second = (int)$alert->add([
            'itemtype' => 'CartridgeItem', 'items_id' => $models['expired'],
            'type' => Alert::END, 'date' => '2000-01-01 13:00:00',
        ]);
        $this->integer($second)->isGreaterThan(0);
        $recent = (int)$alert->add([
            'itemtype' => 'CartridgeItem', 'items_id' => $models['recent'],
            'type' => Alert::THRESHOLD, 'date' => '2037-01-01 12:00:00',
        ]);
        $this->integer($recent)->isGreaterThan(0);

        $read = static fn (): array => Orm::read($DB, static fn (EntityManager $em): array =>
            (new CartridgeRepository($em))->alarmCandidates($entity, 3600));
        $rows = array_values(array_filter($read(), static fn (array $row): bool =>
            in_array((int)$row['cartID'], $models, true)));
        $ids = array_map('intval', array_column($rows, 'cartID'));
        sort($ids);
        $expected = [$models['fresh'], $models['expired'], $models['expired']];
        sort($expected);
        $this->array($ids)->isIdenticalTo($expected);
        foreach ($rows as $row) {
            $this->integer((int)$row['entity'])->isIdenticalTo($entity);
            $this->integer((int)$row['threshold'])->isIdenticalTo(0);
            if ((int)$row['alertID'] === $expired) {
                $this->string($row['date'])->isIdenticalTo('2000-01-01 12:00:00');
            }
        }
        $this->boolean($DB->update('glpi_alerts', ['date' => '2000-01-01 12:00:00'], ['id' => $recent]))->isTrue();
        $this->boolean(in_array($models['recent'], array_map('intval', array_column($read(), 'cartID')), true))->isTrue();
        $this->boolean($alert->delete(['id' => $expired]))->isTrue();
        $this->boolean($alert->delete(['id' => $second]))->isTrue();
        $rows = array_values(array_filter($read(), static fn (array $row): bool =>
            (int)$row['cartID'] === $models['expired']));
        $this->integer(count($rows))->isIdenticalTo(1);
        $this->variable($rows[0]['alertID'])->isNull();
        $this->variable($rows[0]['date'])->isNull();
        $this->integer((int)CartridgeModel::getCount($models['fresh']))->isIdenticalTo(0);
        $null_count = (int)$DB->request([
            'COUNT' => 'cpt', 'FROM' => 'glpi_cartridges', 'WHERE' => ['cartridgeitems_id' => null],
        ])->next()['cpt'];
        $zero_count = (int)$DB->request([
            'COUNT' => 'cpt', 'FROM' => 'glpi_cartridges', 'WHERE' => ['cartridgeitems_id' => 0],
        ])->next()['cpt'];
        $this->integer((int)CartridgeModel::getCount(null))->isIdenticalTo($null_count);
        $this->integer((int)CartridgeModel::getCount('nUlL'))->isIdenticalTo($null_count);
        $this->integer((int)CartridgeModel::getCount(0))->isIdenticalTo($zero_count);
    }

    public function testInfocomInheritance()
    {
        $cartridge = new \Cartridge();

        $cartridge_item = new \CartridgeItem();
        $cu_id = (int) $cartridge_item->add([
           'name' => 'Test cartridge item'
        ]);
        $this->integer($cu_id)->isGreaterThan(0);

        $infocom = new \Infocom();
        $infocom_id = (int) $infocom->add([
           'itemtype'  => \CartridgeItem::getType(),
           'items_id'  => $cu_id,
           'buy_date'  => '2020-10-21',
           'value'     => '500'
        ]);
        $this->integer($infocom_id)->isGreaterThan(0);

        $cartridge_id = $cartridge->add([
           'cartridgeitems_id' => $cu_id
        ]);
        $this->integer($cartridge_id)->isGreaterThan(0);

        $infocom2 = new \Infocom();
        $infocom2_id = (int) $infocom2->getFromDBByCrit([
           'itemtype'  => \Cartridge::getType(),
           'items_id'  => $cartridge_id
        ]);
        $this->integer($infocom2_id)->isGreaterThan(0);
        $this->string($infocom2->fields['buy_date'])->isEqualTo($infocom->fields['buy_date']);
        $this->string($infocom2->fields['value'])->isEqualTo($infocom->fields['value']);
    }

    public function testMassiveActionsUpdatePagesAndBackToStock()
    {
        $this->login();
        $this->setEntity(0, true);
        $old_right = $_SESSION['glpiactiveprofile']['cartridge'] ?? null;
        $_SESSION['glpiactiveprofile']['cartridge'] = 31;

        try {
            $printer = new \Printer();
            $printers_id = (int)$printer->add([
                'name'        => 'massive-printer-' . $this->getUniqueString(),
                'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
            ]);
            $this->integer($printers_id)->isGreaterThan(0);

            $ctype = new \CartridgeItemType();
            $cartridgeitemtypes_id = (int)$ctype->add([
                'name' => 'massive-cart-type-' . $this->getUniqueString(),
            ]);
            $this->integer($cartridgeitemtypes_id)->isGreaterThan(0);

            $citem = new \CartridgeItem();
            $cartridgeitems_id = (int)$citem->add([
                'name'                  => 'massive-cart-item-' . $this->getUniqueString(),
                'cartridgeitemtypes_id' => $cartridgeitemtypes_id,
            ]);
            $this->integer($cartridgeitems_id)->isGreaterThan(0);

            $cartridge = new \Cartridge();
            $cartridges_id = (int)$cartridge->add([
                'name'              => 'massive-cart-' . $this->getUniqueString(),
                'cartridgeitems_id' => $cartridgeitems_id,
            ]);
            $this->integer($cartridges_id)->isGreaterThan(0);

            $this->boolean($cartridge->install($printers_id, $cartridgeitems_id))->isTrue();

            [$ok, $ko] = $this->processMassiveAction(
                'updatepages',
                $cartridge,
                [$cartridges_id],
                ['pages' => 321]
            );
            $this->integer($ok)->isEqualTo(1);
            $this->integer($ko)->isEqualTo(0);

            $this->boolean($cartridge->getFromDB($cartridges_id))->isTrue();
            $this->integer((int)$cartridge->fields['pages'])->isEqualTo(321);

            $this->boolean($printer->getFromDB($printers_id))->isTrue();
            $this->integer((int)$printer->fields['last_pages_counter'])->isEqualTo(321);

            [$ok, $ko] = $this->processMassiveAction('backtostock', $cartridge, [$cartridges_id]);
            $this->integer($ok)->isEqualTo(1);
            $this->integer($ko)->isEqualTo(0);

            $this->boolean($cartridge->getFromDB($cartridges_id))->isTrue();
            $this->integer((int)$cartridge->fields['printers_id'])->isEqualTo(0);
            $this->variable($cartridge->fields['date_use'])->isNull();
            $this->variable($cartridge->fields['date_out'])->isNull();
        } finally {
            if ($old_right === null) {
                unset($_SESSION['glpiactiveprofile']['cartridge']);
            } else {
                $_SESSION['glpiactiveprofile']['cartridge'] = $old_right;
            }
        }
    }
}
