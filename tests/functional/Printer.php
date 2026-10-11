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

use Cartridge as LegacyCartridge;
use CartridgeItem;
use DbTestCase;
use Plugin;
use Printer as LegacyPrinter;
use ReflectionProperty;
use RuntimeException;

/* Test for inc/printer.class.php */

class Printer extends DbTestCase
{
    private $method;

    public function beforeTestMethod($method)
    {
        parent::beforeTestMethod($method);
        //to handle GLPI barbarian replacements.
        $this->method = str_replace(
            ['\\', 'beforeTestMethod'],
            ['', $method],
            __METHOD__
        );
    }

    public function testAdd()
    {
        $obj = new \Printer();

        // Add
        $id = $obj->add([
           'name' => __METHOD__,
           'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true)
        ]);
        $this->integer((int)$id)->isGreaterThan(0);
        $this->boolean($obj->getFromDB($id))->isTrue();

        // getField methods
        $this->variable($obj->getField('id'))->isEqualTo($id);
        $this->string($obj->getField('name'))->isidenticalTo($this->method);

        // fields property
        $this->array($obj->fields)
           ->integer['id']->isEqualTo($id)
           ->string['name']->isidenticalTo($this->method);
    }

    public function testDelete()
    {
        $obj = new \Printer();
        $this->boolean($obj->maybeDeleted())->isTrue();

        // Add
        $id = $obj->add(
            [
         'name' => __METHOD__,
         'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true)]
        );
        $this->integer((int)$id)->isGreaterThan(0);
        $this->boolean($obj->getFromDB($id))->isTrue();
        $this->variable($obj->getField('is_deleted'))->isEqualTo(0);
        $this->variable($obj->isDeleted())->isEqualTo(0);

        // Delete
        $this->boolean($obj->delete(['id' => $id], 0))->isTrue();
        $this->boolean($obj->getFromDB($id))->isTrue();
        $this->variable($obj->getField('is_deleted'))->isEqualTo(1);
        $this->variable($obj->isDeleted())->isEqualTo(1);

        // Restore
        $this->boolean($obj->restore(['id' => $id], 0))->isTrue();
        $this->boolean($obj->getFromDB($id))->isTrue();
        $this->variable($obj->getField('is_deleted'))->isEqualTo(0);
        $this->variable($obj->isDeleted())->isEqualTo(0);

        // Purge
        $this->boolean($obj->delete(['id' => $id], 1))->isTrue();
        $this->boolean($obj->getFromDB($id))->isFalse();
    }

    public function testVisibility()
    {

        $this->login();

        $p = new \Printer();

        // Visibility from root + tree
        $this->setEntity('_test_root_entity', true);
        $this->boolean($p->can(getItemByTypeName('Printer', '_test_printer_all', true), READ))->isTrue();
        $this->boolean($p->can(getItemByTypeName('Printer', '_test_printer_ent0', true), READ))->isTrue();
        $this->boolean($p->can(getItemByTypeName('Printer', '_test_printer_ent1', true), READ))->isTrue();
        $this->boolean($p->can(getItemByTypeName('Printer', '_test_printer_ent2', true), READ))->isTrue();

        // Visibility from root only
        $this->setEntity('_test_root_entity', false);
        $this->boolean($p->can(getItemByTypeName('Printer', '_test_printer_all', true), READ))->isTrue();
        $this->boolean($p->can(getItemByTypeName('Printer', '_test_printer_ent0', true), READ))->isTrue();
        $this->boolean($p->can(getItemByTypeName('Printer', '_test_printer_ent1', true), READ))->isFalse();
        $this->boolean($p->can(getItemByTypeName('Printer', '_test_printer_ent2', true), READ))->isFalse();

        // Visibility from child
        $this->setEntity('_test_child_1', false);
        $this->boolean($p->can(getItemByTypeName('Printer', '_test_printer_all', true), READ))->isTrue();
        $this->boolean($p->can(getItemByTypeName('Printer', '_test_printer_ent0', true), READ))->isFalse();
        $this->boolean($p->can(getItemByTypeName('Printer', '_test_printer_ent1', true), READ))->isTrue();
        $this->boolean($p->can(getItemByTypeName('Printer', '_test_printer_ent2', true), READ))->isFalse();
    }

    public function testDeleteByCriteria()
    {
        $obj = new \Printer();
        $this->boolean($obj->maybeDeleted())->isTrue();

        // Add
        $id = $obj->add([
           'name' => __METHOD__,
           'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true)
        ]);
        $this->integer((int)$id)->isGreaterThan(0);
        $this->boolean($obj->getFromDB($id))->isTrue();
        $this->variable($obj->getField('is_deleted'))->isEqualTo(0);
        ;
        $this->variable($obj->isDeleted())->isEqualTo(0);
        $nb_before = (int)countElementsInTable('glpi_logs', ['itemtype' => 'Printer', 'items_id' => $id]);

        // DeleteByCriteria without history
        $this->boolean($obj->deleteByCriteria(['name' => $this->method], 0, 0))->isTrue();
        $this->boolean($obj->getFromDB($id))->isTrue();
        $this->variable($obj->getField('is_deleted'))->isEqualTo(1);
        $this->variable($obj->isDeleted())->isEqualTo(1);

        $nb_after = (int)countElementsInTable('glpi_logs', ['itemtype' => 'Printer', 'items_id' => $id]);
        $this->integer($nb_after)->isidenticalTo($nb_after);

        // Restore
        $this->boolean($obj->restore(['id' => $id], 0))->isTrue();
        $this->boolean($obj->getFromDB($id))->isTrue();
        $this->variable($obj->getField('is_deleted'))->isEqualTo(0);
        $this->variable($obj->isDeleted())->isEqualTo(0);

        $nb_before = (int)countElementsInTable('glpi_logs', ['itemtype' => 'Printer', 'items_id' => $id]);

        // DeleteByCriteria with history
        $this->boolean($obj->deleteByCriteria(['name' => $this->method], 0, 1))->isTrue;
        $this->boolean($obj->getFromDB($id))->isTrue();
        $this->variable($obj->getField('is_deleted'))->isEqualTo(1);
        $this->variable($obj->isDeleted())->isEqualTo(1);

        $nb_after = (int)countElementsInTable('glpi_logs', ['itemtype' => 'Printer', 'items_id' => $id]);
        $this->integer($nb_after)->isidenticalTo($nb_before + 1);
    }

    public function testClonePreservesCartridgeStockAndOptionalPrinterRole()
    {
        global $DB;

        $this->login();
        $entity = getItemByTypeName('Entity', '_test_root_entity', true);
        $printer = new LegacyPrinter();
        $printerID = $printer->add(['name' => __METHOD__, 'entities_id' => $entity]);
        $this->integer((int)$printerID)->isGreaterThan(0);
        $other = new LegacyPrinter();
        $otherID = $other->add(['name' => __METHOD__ . ' other', 'entities_id' => $entity]);
        $this->integer((int)$otherID)->isGreaterThan(0);
        $model = new CartridgeItem();
        $modelID = $model->add(['name' => __METHOD__, 'entities_id' => $entity]);
        $this->integer((int)$modelID)->isGreaterThan(0);
        $ids = [];
        for ($i = 0; $i < 4; ++$i) {
            $cartridge = new LegacyCartridge();
            $ids[] = $cartridge->add(['cartridgeitems_id' => $modelID]);
            $this->integer((int)$ids[$i])->isGreaterThan(0);
        }
        $cartridge = new LegacyCartridge();
        $this->boolean($cartridge->install($printerID, $modelID))->isTrue();
        $this->boolean($cartridge->install($printerID, $modelID))->isTrue();
        $this->boolean($cartridge->install($otherID, $modelID))->isTrue();
        $this->boolean($cartridge->update([
            'id' => $ids[0], 'date_in' => '2000-01-02', 'pages' => 42,
        ]))->isTrue();
        $this->boolean($cartridge->uninstall($ids[0]))->isTrue();
        $this->boolean($cartridge->update([
            'id' => $ids[1], 'date_in' => '2001-02-03', 'pages' => 7,
        ]))->isTrue();

        $connection = $DB->getDoctrineConnection();
        $table = $connection->quoteIdentifier(LegacyCartridge::getTable());
        $before = $connection->fetchAllAssociative(
            'SELECT * FROM ' . $table . ' WHERE cartridgeitems_id = ? ORDER BY id',
            [$modelID]
        );
        $this->string(LegacyCartridge::getItemField(LegacyPrinter::class))->isIdenticalTo('printers_id');
        $this->string(LegacyCartridge::getItemField(CartridgeItem::class))->isIdenticalTo('cartridgeitems_id');
        $linked = LegacyCartridge::getItemsAssociatedTo(LegacyPrinter::class, $printerID);
        $linkedIDs = array_map(static fn (LegacyCartridge $row): int => (int)$row->getID(), $linked);
        sort($linkedIDs);
        $this->array($linkedIDs)->isIdenticalTo([$ids[0], $ids[1]]);
        $this->exception(static function (): void {
            LegacyCartridge::getItemField('Monitor');
        })->isInstanceOf(RuntimeException::class);

        $cloneID = $printer->clone(['name' => __METHOD__ . ' clone']);
        $this->integer((int)$cloneID)->isGreaterThan(0);
        $copies = $connection->fetchAllAssociative(
            'SELECT * FROM ' . $table . ' WHERE printers_id = ? ORDER BY id',
            [$cloneID]
        );
        $this->integer(count($copies))->isIdenticalTo(2);
        $payloadFields = array_fill_keys(
            ['cartridgeitems_id', 'entities_id', 'date_in', 'date_use', 'date_out', 'pages'],
            true
        );
        $expected = [];
        foreach (array_slice($before, 0, 2) as $row) {
            $expected[$row['date_in']] = array_intersect_key($row, $payloadFields);
        }
        $actual = [];
        foreach ($copies as $row) {
            $this->integer((int)$row['printers_id'])->isIdenticalTo($cloneID);
            $this->boolean(in_array((int)$row['id'], $ids, true))->isFalse();
            $actual[$row['date_in']] = array_intersect_key($row, $payloadFields);
        }
        ksort($expected);
        ksort($actual);
        $this->array($actual)->isIdenticalTo($expected);
        foreach ($before as $row) {
            $this->array($connection->fetchAssociative(
                'SELECT * FROM ' . $table . ' WHERE id = ?',
                [$row['id']]
            ))->isIdenticalTo($row);
        }
        $this->integer(LegacyCartridge::getTotalNumber($modelID))->isIdenticalTo(6);
        $this->integer(LegacyCartridge::getTotalNumberForPrinter($cloneID))->isIdenticalTo(2);
        $this->integer(LegacyCartridge::getUsedNumberForPrinter($cloneID))->isIdenticalTo(1);
        $this->integer(LegacyCartridge::getOldNumberForPrinter($cloneID))->isIdenticalTo(1);

        $clone = new LegacyPrinter();
        $this->boolean($clone->delete(['id' => $cloneID], 1))->isTrue();
        foreach ($copies as $row) {
            $row['printers_id'] = null;
            $this->array($connection->fetchAssociative(
                'SELECT * FROM ' . $table . ' WHERE id = ?',
                [$row['id']]
            ))->isIdenticalTo($row);
        }
        $this->integer(LegacyCartridge::getTotalNumber($modelID))->isIdenticalTo(6);
        $this->integer(LegacyCartridge::getTotalNumberForPrinter($printerID))->isIdenticalTo(2);
        $this->integer(LegacyCartridge::getTotalNumberForPrinter($otherID))->isIdenticalTo(1);

        $stock = new LegacyCartridge();
        $stockID = $stock->add([
            'cartridgeitems_id' => $modelID,
            'printers_id' => $printerID,
            'date_in' => '1999-01-01',
            'date_use' => '1999-01-02',
            'date_out' => '1999-01-03',
            'pages' => 99,
            'clone' => true,
        ]);
        $this->integer((int)$stockID)->isGreaterThan(0);
        $this->boolean($stock->getFromDB($stockID))->isTrue();
        $stockRow = $connection->fetchAssociative(
            'SELECT * FROM ' . $table . ' WHERE id = ?',
            [$stockID]
        );
        $this->variable($stockRow['printers_id'])->isNull();
        $this->variable($stock->fields['date_use'])->isNull();
        $this->variable($stock->fields['date_out'])->isNull();
        $this->string($stock->fields['date_in'])->isIdenticalTo(date('Y-m-d'));
        $this->integer((int)$stock->fields['pages'])->isIdenticalTo(0);
    }

    public function testCartridgeClonePreparationIsolatedAndClearedOnRefusal()
    {
        global $DB, $PLUGIN_HOOKS;

        $this->login();
        $entity = getItemByTypeName('Entity', '_test_root_entity', true);
        $model = new CartridgeItem();
        $modelID = $model->add(['name' => __METHOD__, 'entities_id' => $entity]);
        $this->integer((int)$modelID)->isGreaterThan(0);
        $printer = new LegacyPrinter();
        $printerID = $printer->add(['name' => __METHOD__, 'entities_id' => $entity]);
        $this->integer((int)$printerID)->isGreaterThan(0);
        $source = new LegacyCartridge();
        $sourceID = $source->add(['cartridgeitems_id' => $modelID]);
        $this->integer((int)$sourceID)->isGreaterThan(0);
        $this->boolean($source->install($printerID, $modelID))->isTrue();
        $this->boolean($source->getFromDB($sourceID))->isTrue();
        $connection = $DB->getDoctrineConnection();
        $table = $connection->quoteIdentifier(LegacyCartridge::getTable());
        $before = $connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = ?', [$sourceID]);
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $entered = false;
        $nestedID = null;
        try {
            $plugins->setValue(null, [...$active, 'cartridge_clone_isolation']);
            $PLUGIN_HOOKS['pre_item_add']['cartridge_clone_isolation'][LegacyCartridge::class] = static function (
                LegacyCartridge $item
            ) use (&$entered, &$nestedID, $modelID, $printerID): void {
                if ($entered) {
                    return;
                }
                $entered = true;
                $outer = $item->input;
                try {
                    $nestedID = $item->add([
                        'cartridgeitems_id' => $modelID,
                        'printers_id' => $printerID,
                        'date_use' => '2000-01-01',
                        'pages' => 99,
                        'clone' => true,
                    ]);
                } finally {
                    $item->input = $outer;
                }
            };
            $copyID = $source->clone();
            $this->integer((int)$copyID)->isGreaterThan(0);
            $this->integer((int)$nestedID)->isGreaterThan(0);
            $nested = $connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = ?', [$nestedID]);
            $this->variable($nested['printers_id'])->isNull();
            $this->variable($nested['date_use'])->isNull();
            $this->integer((int)$nested['pages'])->isIdenticalTo(0);
            $copy = $connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = ?', [$copyID]);
            $this->variable($copy['printers_id'])->isIdenticalTo($before['printers_id']);
            $this->variable($copy['date_use'])->isIdenticalTo($before['date_use']);

            foreach ([false, true] as $throw) {
                $refused = null;
                $PLUGIN_HOOKS['pre_item_add']['cartridge_clone_isolation'][LegacyCartridge::class] = static function (
                    LegacyCartridge $item
                ) use (&$refused, $throw): void {
                    $refused = $item;
                    if ($throw) {
                        throw new RuntimeException('Cartridge clone fixture cancellation');
                    }
                    $item->input = false;
                };
                if ($throw) {
                    $this->exception(static function () use ($source): void {
                        $source->clone();
                    })->isInstanceOf(RuntimeException::class)
                        ->hasMessage('Cartridge clone fixture cancellation');
                } else {
                    $this->boolean($source->clone())->isFalse();
                }
                unset($PLUGIN_HOOKS['pre_item_add']['cartridge_clone_isolation'][LegacyCartridge::class]);
                $this->object($refused)->isInstanceOf(LegacyCartridge::class);
                $retryID = $refused->add([
                    'cartridgeitems_id' => $modelID,
                    'printers_id' => $printerID,
                    'date_use' => '2000-01-01',
                    'pages' => 99,
                    'clone' => true,
                ]);
                $this->integer((int)$retryID)->isGreaterThan(0);
                $retry = $connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = ?', [$retryID]);
                $this->variable($retry['printers_id'])->isNull();
                $this->variable($retry['date_use'])->isNull();
                $this->integer((int)$retry['pages'])->isIdenticalTo(0);
            }
        } finally {
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
        $this->array($connection->fetchAssociative(
            'SELECT * FROM ' . $table . ' WHERE id = ?',
            [$sourceID]
        ))->isIdenticalTo($before);
    }
}
