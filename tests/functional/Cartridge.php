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
use Cartridge as StockCartridge;
use Printer as PrinterModel;
use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\OnClearEventArgs;
use itsmng\Database\Entity\Cartridge as CartridgeRecord;
use itsmng\Database\MutationCleanupFailure;
use Log;
use RuntimeException;
use Throwable;
use mock\DBmysql as CartridgeWriterAdapter;
use tests\fixtures\ScalarReadProbe;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Orm;
use itsmng\Database\Repository\CartridgeRepository;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/cartridge.class.php */

class Cartridge extends DbTestCase
{
    public function testPrinterWornAverageUsesOnlyPositiveDisplayedCounters(): void
    {
        $this->login();
        $this->setEntity(0, true);
        $printer = $this->createItem(
            PrinterModel::class,
            [
                'name' => $this->getUniqueString(), 'entities_id' => 0, 'init_pages_counter' => 10,
            ]
        );
        $model = $this->createItem(
            CartridgeModel::class,
            ['name' => $this->getUniqueString(), 'entities_id' => 0]
        );
        $command = new StockCartridge();
        $cartridges = [];
        foreach ([75, 0, 5, 35] as $counter) {
            $cartridge = $this->createItem(StockCartridge::class, ['cartridgeitems_id' => $model->getID()]);
            $cartridges[] = $cartridge;
            $this->boolean($printer->update(['id' => $printer->getID(), 'last_pages_counter' => $counter]))->isTrue();
            $this->boolean($command->install($printer->getID(), $model->getID()))->isTrue();
            $this->boolean($command->uninstall($cartridge->getID()))->isTrue();
        }
        $tables = function (PrinterModel $target): array {
            ob_start();
            try {
                StockCartridge::showForPrinter($target, 1);
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->integer(preg_match_all('/<script type="application\/json"[^>]*>(.*?)<\/script>/s', $html, $matches))
                ->isIdenticalTo(2);
            return array_map(
                static fn (string $json): array =>
                    json_decode($json, true, 512, JSON_THROW_ON_ERROR)['dataSource']['rows'],
                $matches[1]
            );
        };
        $retired = $tables($printer);
        $this->array(array_map('intval', array_column($retired[0], 0)))
            ->isIdenticalTo(array_map(static fn (StockCartridge $cartridge): int => (int)$cartridge->getID(), $cartridges));
        $this->array(array_map('intval', array_column($retired[0], 6)))->isIdenticalTo([75, 0, 5, 35]);
        $this->array(array_column($retired[0], 7))->isIdenticalTo([65, '', '', 25]);
        $this->integer((int)$retired[1][0][2])->isIdenticalTo(45);
        // Equal/reset/missing counters do not enter either side of the average.
        foreach ($cartridges as $cartridge) {
            $this->boolean($cartridge->update(['id' => $cartridge->getID(), 'pages' => 10]))->isTrue();
        }
        $withoutDelta = $tables($printer);
        $this->array(array_column($withoutDelta[0], 7))->isIdenticalTo(['', '', '', '']);
        $this->integer((int)$withoutDelta[1][0][2])->isIdenticalTo(0);
        $emptyPrinter = $this->createItem(PrinterModel::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $empty = $tables($emptyPrinter);
        $this->array($empty[0])->isEmpty();
        $this->array($empty[1])->isIdenticalTo([[]]);
    }

    public function testStockTablesKeepCurrentRowsAndLiveDirtyOwners(): void
    {
        global $DB;
        $this->login();
        $this->setEntity(0, true);
        $printer = $this->createItem(
            PrinterModel::class,
            [
                'name' => $this->getUniqueString(), 'entities_id' => 0,
                'init_pages_counter' => 10, 'last_pages_counter' => 75,
            ]
        );
        $model = $this->createItem(
            CartridgeModel::class,
            [
                'name' => $this->getUniqueString(), 'entities_id' => 0, 'ref' => 'Stock table reference',
            ]
        );
        $worn = $this->createItem(StockCartridge::class, ['cartridgeitems_id' => $model->getID()]);
        $used = $this->createItem(StockCartridge::class, ['cartridgeitems_id' => $model->getID()]);
        $stock = $this->createItem(StockCartridge::class, ['cartridgeitems_id' => $model->getID()]);
        $command = new StockCartridge();
        $this->boolean($command->install($printer->getID(), $model->getID()))->isTrue();
        $this->boolean($command->install($printer->getID(), $model->getID()))->isTrue();
        $this->boolean($command->uninstall($worn->getID()))->isTrue();
        // These views deliberately render a summary/averages table as well as the cartridge table.
        $tables = function (callable $render): array {
            ob_start();
            try {
                $render();
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->integer(preg_match_all('/<script type="application\/json"[^>]*>(.*?)<\/script>/s', $html, $matches))
                ->isIdenticalTo(2);
            return array_map(
                static fn (string $json): array =>
                    json_decode($json, true, 512, JSON_THROW_ON_ERROR)['dataSource']['rows'],
                $matches[1]
            );
        };
        $connection = $DB->getDoctrineConnection();
        $owner = Orm::create($DB);
        try {
            $dirty = $owner->find(CartridgeRecord::class, $used->getID());
            $dirty->pages = 999;
            Orm::read(
                $DB,
                function (EntityManager $outer) use ($printer, $model, $worn, $used, $stock, $command, $tables): void {
                    $pending = $outer->find(CartridgeRecord::class, $stock->getID());
                    $pending->pages = 888;
                    $current = $tables(static fn () => StockCartridge::showForCartridgeItem($model));
                    $this->array($current[0])->isIdenticalTo([[3, 1, 1, 1]]);
                    $this->array(array_map('intval', array_column($current[1], 0)))
                        ->isIdenticalTo([(int)$stock->getID(), (int)$used->getID()]);
                    $this->string($current[1][0][1])->isIdenticalTo(StockCartridge::getStatus(null, null));
                    $this->string($current[1][1][4])->contains($printer->fields['name']);
                    $this->integer(count($current[1][0]))->isIdenticalTo(6);
                    $installed = $this->renderLocalTableRows(static fn () => StockCartridge::showForPrinter($printer));
                    $this->array(array_map('intval', array_column($installed, 0)))->isIdenticalTo([(int)$used->getID()]);
                    $this->string($installed[0][1])->contains('Stock table reference');
                    $old = $this->renderLocalTableRows(static fn () => StockCartridge::showForCartridgeItem($model, 1));
                    $this->array(array_map('intval', array_column($old, 0)))->isIdenticalTo([(int)$worn->getID()]);
                    $this->string($old[0][1])->isIdenticalTo(StockCartridge::getStatus('2000-01-01', '2000-01-02'));
                    $this->string($old[0][6])->contains('65');
                    $retired = $tables(static fn () => StockCartridge::showForPrinter($printer, 1));
                    $this->array(array_map('intval', array_column($retired[0], 0)))->isIdenticalTo([(int)$worn->getID()]);
                    $this->integer((int)$retired[0][0][6])->isIdenticalTo(75);
                    $this->integer((int)$retired[0][0][7])->isIdenticalTo(65);
                    $this->integer((int)$retired[1][0][2])->isIdenticalTo(65);
                    $this->boolean($command->install($printer->getID(), $model->getID()))->isTrue();
                    $this->boolean($command->backToStock(['id' => $used->getID()]))->isTrue();
                    $fresh = $this->renderLocalTableRows(static fn () => StockCartridge::showForPrinter($printer));
                    $this->array(array_map('intval', array_column($fresh, 0)))->isIdenticalTo([(int)$stock->getID()]);
                    $freshModel = $tables(static fn () => StockCartridge::showForCartridgeItem($model));
                    $this->array(array_map('intval', array_column($freshModel[1], 0)))
                        ->isIdenticalTo([(int)$used->getID(), (int)$stock->getID()]);
                    $this->boolean($outer->contains($pending))->isTrue();
                    $this->integer($pending->pages)->isIdenticalTo(888);
                }
            );
            $this->boolean($owner->contains($dirty))->isTrue();
            $this->integer($dirty->pages)->isIdenticalTo(999);
            $this->integer((int)$connection->fetchOne('SELECT pages FROM glpi_cartridges WHERE id = ?', [$used->getID()]))
                ->isIdenticalTo(0);
            $this->integer((int)$connection->fetchOne('SELECT pages FROM glpi_cartridges WHERE id = ?', [$stock->getID()]))
                ->isIdenticalTo(0);
        } finally {
            $owner->clear();
        }
    }

    public function testStockTableCustomReaderClearsBeforeRenderingAndRecovers(): void
    {
        global $DB;
        $this->login();
        $this->setEntity(0, true);
        $original = $DB;
        $connection = $original->getDoctrineConnection();
        $model = $this->createItem(
            CartridgeModel::class,
            ['name' => $this->getUniqueString(), 'entities_id' => 0]
        );
        $failure = new RuntimeException('Custom cartridge table cleanup');
        $listener = new class ($failure) {
            public bool $reject = true;
            public array $owners = [];

            public function __construct(private RuntimeException $failure)
            {
            }

            public function onClear(OnClearEventArgs $event): void
            {
                $this->owners[] = $event->getObjectManager();
                if ($this->reject) {
                    throw $this->failure;
                }
            }
        };
        $events = new EventManager();
        $events->addEventListener([Events::onClear], $listener);
        $selected = new class ($connection, $events, $original) extends ScalarReadProbe {
            public function __construct(Connection $connection, private EventManager $events, private object $original)
            {
                parent::__construct($connection);
            }

            public function getEventManager(): EventManager
            {
                $GLOBALS['DB'] = $this->original;
                return $this->events;
            }
        };
        $this->mockGenerator()->orphanize('__construct');
        $adapter = new CartridgeWriterAdapter();
        $this->calling($adapter)->getDoctrineConnection = $selected;
        $depth = $connection->getTransactionNestingLevel();
        try {
            $DB = $adapter;
            $caught = null;
            ob_start();
            try {
                StockCartridge::showForCartridgeItem($model, 1);
            } catch (RuntimeException $error) {
                $caught = $error;
            } finally {
                $html = ob_get_clean();
            }
            $this->object($caught)->isIdenticalTo($failure);
            $this->string($html)->isEmpty();
            $this->array($listener->owners)->hasSize(1);
            $this->object($listener->owners[0]->getConnection())->isIdenticalTo($selected);
            $listener->reject = false;
            $DB = $adapter;
            $rows = $this->renderLocalTableRows(static fn () => StockCartridge::showForCartridgeItem($model, 1));
            $this->array($rows)->isEmpty();
            $this->array($listener->owners)->hasSize(2);
            $this->object($listener->owners[1])->isNotIdenticalTo($listener->owners[0]);
            $this->object($listener->owners[1]->getConnection())->isIdenticalTo($selected);
            $this->array($selected->queries)->hasSize(2);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
        } finally {
            $DB = $original;
        }
    }

    public function testStockMutationsStayCurrentWithoutFlushingDirtyOwners(): void
    {
        global $DB;
        $this->login();
        $this->setEntity(0, true);
        $printer = $this->createItem(PrinterModel::class, [
            'name' => $this->getUniqueString(), 'entities_id' => 0, 'last_pages_counter' => 75,
        ]);
        $model = $this->createItem(CartridgeModel::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $first = $this->createItem(StockCartridge::class, ['cartridgeitems_id' => $model->getID()]);
        $second = $this->createItem(StockCartridge::class, ['cartridgeitems_id' => $model->getID()]);
        $connection = $DB->getDoctrineConnection();
        $messages = static fn (): array => $connection->fetchFirstColumn(
            'SELECT new_value FROM glpi_logs WHERE itemtype = ? AND items_id = ? AND linked_action = ? ORDER BY id',
            ['Printer', $printer->getID(), Log::HISTORY_LOG_SIMPLE_MESSAGE]
        );
        $before = $messages();
        $owner = Orm::create($DB);
        try {
            $dirty = $owner->find(CartridgeRecord::class, $first->getID());
            $dirty->pages = 999;
            $command = new StockCartridge();
            $this->boolean($command->install((string)$printer->getID(), (string)$model->getID()))->isTrue();
            Orm::withConnection($connection, function (EntityManager $outer) use ($second, $printer, $model, $command): void {
                $pending = $outer->find(CartridgeRecord::class, $second->getID());
                $pending->pages = 888;
                $this->boolean($command->install($printer->getID(), $model->getID()))->isTrue();
                $this->boolean($outer->contains($pending))->isTrue();
                $this->integer($pending->pages)->isIdenticalTo(888);
            });
            foreach ([$first, $second] as $cartridge) {
                $this->boolean($cartridge->getFromDB($cartridge->getID()))->isTrue();
                $this->integer((int)$cartridge->fields['printers_id'])->isIdenticalTo((int)$printer->getID());
                $this->string($cartridge->fields['date_use'])->isIdenticalTo((new DateTimeImmutable('today'))->format('Y-m-d'));
                $this->integer((int)$cartridge->fields['pages'])->isIdenticalTo(0);
            }
            $this->boolean($command->install($printer->getID(), $model->getID()))->isFalse();
            $this->hasSessionMessages(ERROR, ['No free cartridge']);
            $this->boolean($command->uninstall($first->getID()))->isTrue();
            $this->boolean($first->getFromDB($first->getID()))->isTrue();
            $this->integer((int)$first->fields['pages'])->isIdenticalTo(75);
            $this->string($first->fields['date_out'])->isIdenticalTo((new DateTimeImmutable('today'))->format('Y-m-d'));
            $this->boolean($command->uninstall($first->getID()))->isFalse();
            $this->array(array_slice($messages(), count($before)))->isIdenticalTo([
                __('Installing a cartridge'), __('Installing a cartridge'), __('Uninstalling a cartridge'),
            ]);
            $this->boolean($owner->contains($dirty))->isTrue();
            $this->integer($dirty->pages)->isIdenticalTo(999);
        } finally {
            $owner->clear();
        }
    }

    public function testStockWriterKeepsCapturedCustomRouteAndBothActualFailures(): void
    {
        global $DB;
        $this->login();
        $this->setEntity(0, true);
        $original = $DB;
        $connection = $original->getDoctrineConnection();
        $printer = $this->createItem(PrinterModel::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $model = $this->createItem(CartridgeModel::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $stock = $this->createItem(StockCartridge::class, ['cartridgeitems_id' => $model->getID()]);
        $cleanup = new RuntimeException('Custom stock writer cleanup');
        $listener = new class ($cleanup) {
            public bool $reject = true;
            public array $owners = [];

            public function __construct(private RuntimeException $failure)
            {
            }

            public function onClear(OnClearEventArgs $event): void
            {
                $this->owners[] = $event->getObjectManager();
                if ($this->reject) {
                    throw $this->failure;
                }
            }
        };
        $events = new EventManager();
        $events->addEventListener([Events::onClear], $listener);
        $selected = new class ($connection, $events, $original) extends ScalarReadProbe {
            public ?Throwable $primary = null;

            public function __construct(Connection $connection, private EventManager $events, private object $original)
            {
                parent::__construct($connection);
            }

            public function getEventManager(): EventManager
            {
                $GLOBALS['DB'] = $this->original;
                return $this->events;
            }

            public function executeStatement(string $sql, array $params = [], array $types = []): int|string
            {
                try {
                    return $this->selected->executeStatement($sql, $params, $types);
                } catch (Throwable $error) {
                    $this->primary = $error;
                    throw $error;
                }
            }
        };
        $this->mockGenerator()->orphanize('__construct');
        $adapter = new CartridgeWriterAdapter();
        $this->calling($adapter)->getDoctrineConnection = $selected;
        $before = $connection->fetchAssociative('SELECT * FROM glpi_cartridges WHERE id = ?', [$stock->getID()]);
        $depth = $connection->getTransactionNestingLevel();
        try {
            $caught = null;
            $DB = $adapter;
            try {
                $connection->transactional(static fn () => (new StockCartridge())->install(PHP_INT_MAX, $model->getID()));
            } catch (MutationCleanupFailure $error) {
                $caught = $error;
            }
            $this->object($caught)->isInstanceOf(MutationCleanupFailure::class);
            $this->object($caught->primary)->isInstanceOf(ForeignKeyConstraintViolationException::class)->isIdenticalTo($selected->primary);
            $this->object($caught->cleanup)->isIdenticalTo($cleanup);
            $this->array($listener->owners)->hasSize(1);
            $this->object($listener->owners[0]->getConnection())->isIdenticalTo($selected);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
            $this->array($connection->fetchAssociative('SELECT * FROM glpi_cartridges WHERE id = ?', [$stock->getID()]))->isIdenticalTo($before);
            $listener->reject = false;
            $DB = $adapter;
            $this->boolean((new StockCartridge())->install($printer->getID(), $model->getID()))->isTrue();
            $this->array($listener->owners)->hasSize(2);
            $this->object($listener->owners[1])->isNotIdenticalTo($listener->owners[0]);
            $this->object($listener->owners[1]->getConnection())->isIdenticalTo($selected);
            $this->boolean($stock->getFromDB($stock->getID()))->isTrue();
            $this->integer((int)$stock->fields['printers_id'])->isIdenticalTo((int)$printer->getID());
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
        } finally {
            $DB = $original;
        }
    }

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
