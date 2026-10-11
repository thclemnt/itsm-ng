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

use atoum\atoum\mock\controller as MockController;
use Closure;
use Cartridge as LegacyCartridge;
use DateTime;
use CommonDBChild;
use CommonDBTM;
use CommonDBRelation;
use CommonITILActor as ItilActorModel;
use Computer;
use Computer_Item as ComputerItemModel;
use Printer as PrinterAsset;
use Contact as LegacyContact;
use Contact_Supplier as ContactSupplierModel;
use DBAdapter;
use DbTestCase;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Infocom as InfocomModel;
use PluginFooBar as FinancialPluginOwner;
use Item_SoftwareVersion;
use Link;
use Link_Itemtype;
use MassiveAction;
use Location as LocationModel;
use Netpoint as NetpointModel;
use NetworkPort as NetworkPortModel;
use NetworkPort_NetworkPort as PortConnectionModel;
use NetworkPortEthernet as EthernetModel;
use Plugin;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use RuntimeException;
use Software;
use SoftwareVersion;
use Supplier as SupplierModel;
use LogicException;
use itsmng\Database\Repository\ITILActorRepository;
use Supplier_Ticket as SupplierTicketModel;
use itsmng\Database\Entity\Change as ChangeRecord;
use itsmng\Database\Entity\Problem as ProblemRecord;
use itsmng\Database\Entity\ChangeTask as ChangeTaskRecord;
use itsmng\Database\Entity\ProblemTask as ProblemTaskRecord;
use itsmng\Database\Entity\TicketTask as TicketTaskRecord;
use itsmng\Database\Entity\TaskCategory as TaskCategoryRecord;
use itsmng\Database\Entity\SupplierTicket as SupplierTicketRecord;
use itsmng\Database\Entity\ProblemSupplier as ProblemSupplierRecord;
use itsmng\Database\Entity\ChangeSupplier as ChangeSupplierRecord;
use Toolbox;
use Throwable;
use Transfer as LegacyTransfer;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Entity\Computer as ComputerEntity;
use itsmng\Database\Entity\Cartridge;
use itsmng\Database\Entity\CartridgeItem;
use itsmng\Database\Entity\CartridgeItemPrinterModel;
use itsmng\Database\Entity\Printer as PrinterEntity;
use itsmng\Database\Entity\PrinterModel;
use itsmng\Database\Entity\Contract as ContractRecord;
use itsmng\Database\Entity\ContractSupplier as ContractSupplierRecord;
use itsmng\Database\Entity\Infocom as InfocomRecord;
use itsmng\Database\Entity\Contact;
use itsmng\Database\Entity\ContactSupplier;
use itsmng\Database\Entity\Supplier;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\ItemSoftwareVersion;
use itsmng\Database\Entity\ItemTicket;
use itsmng\Database\Entity\Log;
use itsmng\Database\Entity\Netpoint as NetpointRecord;
use itsmng\Database\Entity\Software as SoftwareEntity;
use itsmng\Database\Entity\SoftwareVersion as SoftwareVersionEntity;
use itsmng\Database\Entity\Ticket;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\MySQLConnection;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\Repository\SupplierTransferRepository;
use itsmng\Database\PostgresConnection;
use itsmng\Database\TransactionOwnershipMismatch;
use itsmng\Domain\SoftwareAssignmentCancelled;
use itsmng\Domain\SoftwareAssignmentService;
use mock\DBmysql as NetpointRouteAdapter;
use tests\fixtures\ScalarReadProbe;

use function getItemTypeForTable;

require_once __DIR__ . '/../fixtures/ScalarReadProbe.php';
require_once __DIR__ . '/../fixtures/pluginfoobar.php';

/* Test for inc/transfer.class.php */

class Transfer extends DbTestCase
{
    public function testFinancialSupplierTransferUsesActualOutsideOwnersAndCurrentDestinations(): void
    {
        global $CFG_GLPI;
        $configuration = $CFG_GLPI;
        $tables = new ReflectionProperty(CommonDBTM::class, 'tables_of');
        $savedTables = $tables->getValue();
        $right = FinancialPluginOwner::$rightname;
        try {
            FinancialPluginOwner::$rightname = 'computer';
            FinancialPluginOwner::forceTable('glpi_computers');
            $CFG_GLPI['glpitablesitemtype'][FinancialPluginOwner::class] = 'glpi_computers';
            $CFG_GLPI['infocom_types'][] = FinancialPluginOwner::class;
            $this->withSoftwareOwnerQueryProbe(function ($database, $connection): void {
                $this->login();
                $this->setEntity('_test_root_entity', true);
                $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
                $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
                $manager = Orm::create($database);
                foreach ([
                    ['exclusive', $this->getUniqueString()],
                    ['selected-contract', $this->getUniqueString()],
                    ['outside-contract', $this->getUniqueString()],
                    ['outside-financial', $this->getUniqueString()],
                    ['plugin-financial', $this->getUniqueString()],
                    ['reuse', 'NULL'],
                    ['reuse', 'null'],
                    ['reuse', "Supplier O'Brien\\route " . $this->getUniqueString()],
                ] as [$mode, $name]) {
                    $supplier = new Supplier();
                    $supplier->entities = $manager->getReference(Entity::class, $source);
                    $supplier->name = $name;
                    $supplier->comment = 'Current source supplier';
                    $manager->persist($supplier);
                    $computers = [];
                    $financial = [];
                    foreach ([0, 1] as $index) {
                        $computer = new ComputerEntity();
                        $computer->entities = $manager->getReference(Entity::class, $source);
                        $computer->name = $this->getUniqueString();
                        $manager->persist($computer);
                        $computers[] = $computer;
                        $manager->flush();
                        if ($index === 0 || in_array($mode, ['outside-financial', 'reuse'], true)) {
                            $row = new InfocomRecord();
                            $row->entities = $manager->getReference(Entity::class, $source);
                            $row->itemtype = 'Computer';
                            $row->items_id = $computer->id;
                            $row->suppliers = $supplier;
                            $manager->persist($row);
                            $financial[] = [$row, $computer];
                        }
                    }
                    if (in_array($mode, ['selected-contract', 'outside-contract'], true)) {
                        $contract = new ContractRecord();
                        $contract->entities = $manager->getReference(Entity::class, $source);
                        $contract->name = $this->getUniqueString();
                        $manager->persist($contract);
                        $link = new ContractSupplierRecord();
                        $link->suppliers = $supplier;
                        $link->contracts = $contract;
                        $manager->persist($link);
                    }
                    $destination = null;
                    if ($mode === 'reuse') {
                        $destination = new Supplier();
                        $destination->entities = $manager->getReference(Entity::class, $target);
                        $destination->name = 'Previous destination ' . $this->getUniqueString();
                        $destination->is_deleted = true;
                        $manager->persist($destination);
                        $decoy = new Supplier();
                        $decoy->entities = $manager->getReference(Entity::class, $target);
                        $decoy->name = null;
                        $manager->persist($decoy);
                    }
                    $manager->flush();
                    $items = ['Computer' => [$computers[0]->id]];
                    if ($mode === 'selected-contract') {
                        $items['Contract'] = [$contract->id];
                    } elseif ($mode === 'plugin-financial') {
                        $pluginOwners = [];
                        foreach ([0, 1] as $index) {
                            $owner = $this->createItem(FinancialPluginOwner::class, [
                                'name' => $this->getUniqueString(),
                                'entities_id' => $source,
                            ]);
                            $pluginOwners[] = $owner;
                        }
                        $kind = $pluginOwners[1]->getType();
                        $this->string($kind)->isIdenticalTo(FinancialPluginOwner::class);
                        $row = new InfocomModel();
                        $id = $row->add(Toolbox::addslashes_deep([
                            'itemtype' => $kind,
                            'items_id' => $pluginOwners[1]->getID(),
                            'suppliers_id' => $supplier->id,
                            'entities_id' => $source,
                        ]));
                        $this->integer((int)$id)->isGreaterThan(0);
                        $this->string($connection->fetchOne('SELECT itemtype FROM glpi_infocoms WHERE id=?', [$id]))->isIdenticalTo($kind);
                        $items[$pluginOwners[0]->getType()] = [$pluginOwners[0]->getID()];
                    }
                    $expected = [];
                    $expectedNames = [];
                    if ($destination !== null) {
                        $connection->update('glpi_suppliers', ['name' => $name], ['id' => $destination->id]);
                        $candidates = $connection->fetchAllAssociative(
                            'SELECT id, name FROM glpi_suppliers WHERE entities_id=? AND name=?',
                            [$target, $name]
                        );
                        $expected = array_map('intval', array_column($candidates, 'id'));
                        $expectedNames = array_column($candidates, 'name', 'id');
                        $destination->name = 'Unflushed independent destination';
                    }
                    $supplier->comment = 'Unflushed independent supplier';
                    $connection->withApplicationEntityManager(function ($outer) use ($items, $mode, $supplier, $financial, $computers, $destination, $expected, $expectedNames, $name, $source, $target, $manager, $connection): void {
                        $retained = $outer->find(Supplier::class, $supplier->id);
                        $retained->comment = 'Unflushed outer supplier';
                        $transfer = new LegacyTransfer();
                        $this->boolean($transfer->moveItems(
                            $items,
                            $target,
                            [
                                'keep_infocom' => 1,
                                'keep_supplier' => 1,
                                'keep_contract' => $mode === 'selected-contract' ? 1 : 0,
                            ]
                        ))->isTrue();
                        $chosen = (int)$connection->fetchOne('SELECT suppliers_id FROM glpi_infocoms WHERE id=?', [$financial[0][0]->id]);
                        if (in_array($mode, ['exclusive', 'selected-contract'], true)) {
                            $this->integer($chosen)->isIdenticalTo($supplier->id);
                            $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_suppliers WHERE id=?', [$supplier->id]))->isIdenticalTo($target);
                        } else {
                            $this->integer($chosen)->isGreaterThan(0)->isNotIdenticalTo($supplier->id);
                            $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_suppliers WHERE id=?', [$supplier->id]))->isIdenticalTo($source);
                            if ($destination !== null) {
                                $this->boolean(in_array($chosen, $expected, true))->isTrue();
                                $this->string($connection->fetchOne('SELECT name FROM glpi_suppliers WHERE id=?', [$chosen]))->isIdenticalTo($expectedNames[$chosen]);
                                $this->boolean($manager->contains($destination))->isTrue();
                                $this->string($destination->name)->isIdenticalTo('Unflushed independent destination');
                            } else {
                                $this->string($connection->fetchOne('SELECT name FROM glpi_suppliers WHERE id=?', [$chosen]))->isIdenticalTo($name);
                                $this->string($connection->fetchOne('SELECT comment FROM glpi_suppliers WHERE id=?', [$chosen]))->isIdenticalTo('Current source supplier');
                            }
                        }
                        if (count($financial) === 2) {
                            $this->integer((int)$connection->fetchOne('SELECT suppliers_id FROM glpi_infocoms WHERE id=?', [$financial[1][0]->id]))->isIdenticalTo($supplier->id);
                        }
                        $this->integer((int)$transfer->already_transfer['Supplier'][$supplier->id])->isIdenticalTo($chosen);
                        $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_computers WHERE id=?', [$computers[0]->id]))->isIdenticalTo($target);
                        $this->boolean($manager->contains($supplier))->isTrue();
                        $this->string($supplier->comment)->isIdenticalTo('Unflushed independent supplier');
                        $this->boolean($outer->contains($retained))->isTrue();
                        $this->string($retained->comment)->isIdenticalTo('Unflushed outer supplier');
                    });
                    $manager->detach($supplier);
                    if ($destination !== null) {
                        $manager->detach($destination);
                    }
                }
            });
        } finally {
            FinancialPluginOwner::$rightname = $right;
            $tables->setValue(null, $savedTables);
            $CFG_GLPI = $configuration;
        }
    }

    public function testFinancialSupplierCopyPublicFailuresRollBackAndRecover(): void
    {
        global $PLUGIN_HOOKS;
        $savedHooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        try {
            $plugins->setValue(null, [...$savedPlugins, 'transfer_financial_supplier_fixture']);
            $this->withSoftwareOwnerQueryProbe(function ($database, $connection) use ($savedHooks): void {
                global $PLUGIN_HOOKS;
                $this->login();
                $this->setEntity('_test_root_entity', true);
                $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
                $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
                $manager = Orm::create($database);
                $supplier = new Supplier();
                $supplier->entities = $manager->getReference(Entity::class, $source);
                $supplier->name = 'NULL';
                $supplier->comment = 'null';
                $manager->persist($supplier);
                $computers = [];
                $financial = [];
                foreach ([0, 1] as $index) {
                    $computer = new ComputerEntity();
                    $computer->entities = $manager->getReference(Entity::class, $source);
                    $computer->name = $this->getUniqueString();
                    $manager->persist($computer);
                    $computers[] = $computer;
                    $manager->flush();
                    $row = new InfocomRecord();
                    $row->entities = $manager->getReference(Entity::class, $source);
                    $row->itemtype = 'Computer';
                    $row->items_id = $computer->id;
                    $row->suppliers = $supplier;
                    $manager->persist($row);
                    $financial[] = $row;
                }
                $contract = new ContractRecord();
                $contract->entities = $manager->getReference(Entity::class, $source);
                $manager->persist($contract);
                $link = new ContractSupplierRecord();
                $link->suppliers = $supplier;
                $link->contracts = $contract;
                $manager->persist($link);
                $namespaceKind = 'GlpiPlugin\\Bar\\Foo';
                $namespaceFinancial = new InfocomRecord();
                $namespaceFinancial->entities = $manager->getReference(Entity::class, $source);
                $namespaceFinancial->itemtype = $namespaceKind;
                $namespaceFinancial->items_id = $computers[1]->id;
                $namespaceFinancial->suppliers = $supplier;
                $manager->persist($namespaceFinancial);
                $manager->flush();
                $this->string($connection->fetchOne('SELECT itemtype FROM glpi_infocoms WHERE id=?', [$namespaceFinancial->id]))->isIdenticalTo($namespaceKind);
                $repository = new SupplierTransferRepository($manager);
                $this->integer($repository->countOtherFinancialOwners($supplier->id, $namespaceKind, []))->isIdenticalTo(1);
                $this->integer($repository->countOtherFinancialOwners($supplier->id, $namespaceKind, [$computers[1]->id]))->isIdenticalTo(0);
                $this->integer($repository->countOtherContractOwners($supplier->id, []))->isIdenticalTo(1);
                $this->integer($repository->countOtherContractOwners($supplier->id, [0, -1]))->isIdenticalTo(1);
                $this->integer($repository->countOtherContractOwners($supplier->id, [null]))->isIdenticalTo(0);
                $this->integer($repository->countOtherContractOwners($supplier->id, [$contract->id]))->isIdenticalTo(0);
                $this->integer($repository->countOtherFinancialOwners($supplier->id, 'Computer', []))->isIdenticalTo(2);
                $this->integer($repository->countOtherFinancialOwners($supplier->id, 'Computer', [0, -1]))->isIdenticalTo(2);
                $this->integer($repository->countOtherFinancialOwners($supplier->id, 'Computer', [$computers[0]->id, null]))->isIdenticalTo(0);
                $this->integer($repository->countOtherFinancialOwners($supplier->id, 'Computer', [$computers[0]->id, 'NULL']))->isIdenticalTo(0);
                $supplier->comment = 'Unflushed failure owner';
                $before = [];
                foreach (['glpi_suppliers', 'glpi_infocoms', 'glpi_computers', 'glpi_contracts_suppliers', 'glpi_logs'] as $table) {
                    $before[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
                }
                $depth = $connection->getTransactionNestingLevel();
                foreach (['pre_item_add', 'item_add'] as $hook) {
                    $PLUGIN_HOOKS = $savedHooks;
                    $calls = 0;
                    $PLUGIN_HOOKS[$hook]['transfer_financial_supplier_fixture'][SupplierModel::class] = static function (SupplierModel $model) use ($hook, &$calls): void {
                        ++$calls;
                        if ($hook === 'pre_item_add') {
                            $model->input = false;
                        } else {
                            throw new RuntimeException('Financial supplier postSQL failure');
                        }
                    };
                    $transfer = new LegacyTransfer();
                    $failure = null;
                    $result = null;
                    try {
                        $result = $transfer->moveItems(['Computer' => [$computers[0]->id]], $target, ['keep_infocom' => 1, 'keep_supplier' => 1]);
                    } catch (Throwable $error) {
                        $failure = $error;
                    }
                    if ($failure !== null) {
                        $this->string($failure->getMessage())->contains($hook === 'pre_item_add' ? 'Supplier add' : 'Financial supplier postSQL failure');
                    } else {
                        $this->boolean($result)->isFalse();
                    }
                    $this->integer($calls)->isIdenticalTo(1);
                    foreach ($before as $table => $rows) {
                        $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id'))->isIdenticalTo($rows);
                    }
                    $this->array($transfer->already_transfer)->isEmpty();
                    $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
                    $this->boolean($manager->contains($supplier))->isTrue();
                    $this->string($supplier->comment)->isIdenticalTo('Unflushed failure owner');
                }
                $PLUGIN_HOOKS = $savedHooks;
                $this->boolean((new LegacyTransfer())->moveItems(['Computer' => [$computers[0]->id]], $target, ['keep_infocom' => 1, 'keep_supplier' => 1]))->isTrue();
                $copy = (int)$connection->fetchOne('SELECT suppliers_id FROM glpi_infocoms WHERE id=?', [$financial[0]->id]);
                $this->integer($copy)->isGreaterThan(0)->isNotIdenticalTo($supplier->id);
                $this->string($connection->fetchOne('SELECT name FROM glpi_suppliers WHERE id=?', [$copy]))->isIdenticalTo('NULL');
                $this->string($connection->fetchOne('SELECT comment FROM glpi_suppliers WHERE id=?', [$copy]))->isIdenticalTo('null');
                $this->integer((int)$connection->fetchOne('SELECT suppliers_id FROM glpi_infocoms WHERE id=?', [$financial[1]->id]))->isIdenticalTo($supplier->id);
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
            });
        } finally {
            $PLUGIN_HOOKS = $savedHooks;
            $plugins->setValue(null, $savedPlugins);
        }
    }

    public function testItilTransferRetargetsEverySupplierAndTheActualTaskFamily(): void
    {
        $this->withSoftwareOwnerQueryProbe(function ($database, $connection): void {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
            foreach ([
                ['Ticket', Ticket::class, SupplierTicketRecord::class, TicketTaskRecord::class, 'tickets'],
                ['Problem', ProblemRecord::class, ProblemSupplierRecord::class, ProblemTaskRecord::class, 'problems'],
                ['Change', ChangeRecord::class, ChangeSupplierRecord::class, ChangeTaskRecord::class, 'changes'],
            ] as [$kind, $parentClass, $actorClass, $taskClass, $parentProperty]) {
                $manager = Orm::create($database);
                $parent = new $parentClass();
                $parent->entities = $manager->getReference(Entity::class, $source);
                $parent->name = 'Selected ' . $kind . ' ' . $this->getUniqueString();
                $manager->persist($parent);
                $suppliers = [];
                $links = [];
                $copyName = "Supplier O'Brien\\route " . $this->getUniqueString();
                foreach ([$copyName, 'NULL', 'null', null] as $name) {
                    $supplier = new Supplier();
                    $supplier->entities = $manager->getReference(Entity::class, $source);
                    $supplier->name = $name;
                    $manager->persist($supplier);
                    $suppliers[] = $supplier;
                    $link = new $actorClass();
                    $link->$parentProperty = $parent;
                    $link->actor = $supplier;
                    $link->type = $name === 'null' ? ItilActorModel::OBSERVER : ItilActorModel::ASSIGN;
                    $link->alternative_email = 'retained@example.com';
                    $manager->persist($link);
                    $links[] = $link;
                }
                $destinations = [];
                foreach (['NULL', 'null', '', null] as $name) {
                    $destination = new Supplier();
                    $destination->entities = $manager->getReference(Entity::class, $target);
                    $destination->name = $name;
                    $manager->persist($destination);
                    $destinations[] = $destination;
                }
                $categories = [];
                foreach (['Reused category ' . $this->getUniqueString(), "Imported O'Brien " . $this->getUniqueString()] as $name) {
                    $category = new TaskCategoryRecord();
                    $category->entities = $manager->getReference(Entity::class, $source);
                    $category->name = $category->completename = $name;
                    $category->level = 1;
                    $manager->persist($category);
                    $categories[] = $category;
                }
                $reused = new TaskCategoryRecord();
                $reused->entities = $manager->getReference(Entity::class, $target);
                $reused->name = $reused->completename = $categories[0]->name;
                $reused->level = 1;
                $manager->persist($reused);
                $tasks = [];
                foreach ([$categories[0], $categories[1], null] as $category) {
                    $task = new $taskClass();
                    $task->$parentProperty = $parent;
                    $task->taskcategories = $category;
                    $task->content = 'Retained task content';
                    $task->is_private = $category === $categories[1];
                    $manager->persist($task);
                    $tasks[] = $task;
                }
                $otherProblem = null;
                $otherTask = null;
                if ($kind === 'Change') {
                    $otherProblem = new ProblemRecord();
                    $otherProblem->entities = $manager->getReference(Entity::class, $source);
                    $otherProblem->name = 'Unrelated colliding problem';
                    $manager->persist($otherProblem);
                    $otherTask = new ProblemTaskRecord();
                    $otherTask->problems = $otherProblem;
                    $otherTask->taskcategories = $categories[0];
                    $otherTask->content = 'Unrelated problem task must remain';
                    $manager->persist($otherTask);
                }
                $manager->flush();
                $taskTable = $manager->getClassMetadata($taskClass)->getTableName();
                $actorTable = $manager->getClassMetadata($actorClass)->getTableName();
                $parentField = $manager->getClassMetadata($actorClass)->associationMappings[$parentProperty]->joinColumns[0]->name;
                $taskIDs = array_map(static fn ($task): int => $task->id, $tasks);
                $otherRow = null;
                if ($otherTask !== null) {
                    // Legal table-local IDs deliberately collide; all owning references remain real.
                    $collision = 100 + max(
                        (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_changetasks'),
                        (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_problemtasks')
                    );
                    $connection->update('glpi_changetasks', ['id' => $collision], ['id' => $taskIDs[0]]);
                    $connection->update('glpi_problemtasks', ['id' => $collision], ['id' => $otherTask->id]);
                    $taskIDs[0] = $collision;
                    $otherRow = $connection->fetchAssociative('SELECT * FROM glpi_problemtasks WHERE id=?', [$collision]);
                }
                $bindingRows = [];
                foreach ($links as $link) {
                    $bindingRows[$link->id] = $connection->fetchAssociative('SELECT * FROM ' . $actorTable . ' WHERE id=?', [$link->id]);
                }
                $eligibleDestinations = [];
                foreach (['NULL', 'null', ''] as $name) {
                    $eligibleDestinations[$name] = array_map('intval', $connection->fetchFirstColumn(
                        'SELECT id FROM glpi_suppliers WHERE entities_id=? AND name=?',
                        [$target, $name]
                    ));
                    $this->array($eligibleDestinations[$name])->isNotEmpty();
                }
                $destinations[0]->comment = 'Unflushed independent supplier';
                Orm::read($database, function (EntityManager $outer) use ($parent, $kind, $target, $destinations): void {
                    $dirty = $outer->find(Supplier::class, $destinations[0]->id);
                    $dirty->comment = 'Unflushed enclosing supplier';
                    $this->boolean((new LegacyTransfer())->moveItems([$kind => [$parent->id]], $target, []))->isTrue();
                    $this->boolean($outer->contains($dirty))->isTrue();
                    $this->string($dirty->comment)->isIdenticalTo('Unflushed enclosing supplier');
                });
                foreach ($links as $index => $link) {
                    $row = $connection->fetchAssociative('SELECT * FROM ' . $actorTable . ' WHERE id=?', [$link->id]);
                    $supplierID = (int)$row['suppliers_id'];
                    $this->integer($supplierID)->isGreaterThan(0)->isNotIdenticalTo($suppliers[$index]->id);
                    $destination = $connection->fetchAssociative('SELECT * FROM glpi_suppliers WHERE id=?', [$supplierID]);
                    $this->integer((int)$destination['entities_id'])->isIdenticalTo($target);
                    if ($index === 0) {
                        $this->string($destination['name'])->isIdenticalTo($copyName);
                    } elseif ($index === 3) {
                        $this->array($eligibleDestinations[''])->contains($supplierID);
                        $this->string($destination['name'])->isIdenticalTo('');
                    } else {
                        $this->array($eligibleDestinations[$index === 1 ? 'NULL' : 'null'])->contains($supplierID);
                        $this->string(strtolower($destination['name']))->isIdenticalTo('null');
                        $this->integer($supplierID)->isNotIdenticalTo($destinations[3]->id);
                    }
                    $this->integer((int)$row['actor_key'])->isIdenticalTo($supplierID);
                    unset($row['suppliers_id'], $row['actor_key'], $bindingRows[$link->id]['suppliers_id'], $bindingRows[$link->id]['actor_key']);
                    $this->array($row)->isIdenticalTo($bindingRows[$link->id]);
                    $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_suppliers WHERE id=?', [$suppliers[$index]->id]))->isIdenticalTo($source);
                }
                foreach ($taskIDs as $index => $id) {
                    $row = $connection->fetchAssociative('SELECT * FROM ' . $taskTable . ' WHERE id=?', [$id]);
                    $this->integer((int)$row[$parentField])->isIdenticalTo($parent->id);
                    $this->string($row['content'])->isIdenticalTo('Retained task content');
                    if ($index === 2) {
                        $this->variable($row['taskcategories_id'])->isNull();
                    } else {
                        $category = $connection->fetchAssociative('SELECT * FROM glpi_taskcategories WHERE id=?', [$row['taskcategories_id']]);
                        $this->integer((int)$category['entities_id'])->isIdenticalTo($target);
                        $this->string($category['completename'])->isIdenticalTo($categories[$index]->completename);
                        if ($index === 0) {
                            $this->integer((int)$row['taskcategories_id'])->isIdenticalTo($reused->id);
                        }
                    }
                }
                if ($otherRow !== null) {
                    $this->array($connection->fetchAssociative('SELECT * FROM glpi_problemtasks WHERE id=?', [$taskIDs[0]]))->isIdenticalTo($otherRow);
                    $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_problems WHERE id=?', [$otherProblem->id]))->isIdenticalTo($source);
                }
                $this->boolean($manager->contains($destinations[0]))->isTrue();
                $this->string($destinations[0]->comment)->isIdenticalTo('Unflushed independent supplier');
                $this->variable($connection->fetchOne('SELECT comment FROM glpi_suppliers WHERE id=?', [$destinations[0]->id]))->isNull();
                $manager->clear();
            }
        });
    }

    public function testItilSupplierTransferUsesFreshLookupsAndRollsBackLaterRefusals(): void
    {
        global $PLUGIN_HOOKS;
        $savedHooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        try {
            $plugins->setValue(null, [...$savedPlugins, 'transfer_itil_fixture']);
            $this->withSoftwareOwnerQueryProbe(function ($database, $connection) use ($savedHooks): void {
                $this->login();
                $this->setEntity('_test_root_entity', true);
                global $PLUGIN_HOOKS;
                $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
                $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
                $copyManager = Orm::create($database);
                $copyTicket = new Ticket();
                $copyTicket->entities = $copyManager->getReference(Entity::class, $source);
                $copyTicket->name = 'Actual supplier copy hook';
                $sourceSupplier = new Supplier();
                $sourceSupplier->entities = $copyManager->getReference(Entity::class, $source);
                $sourceSupplier->name = 'Copy hook supplier ' . $this->getUniqueString();
                $copyLink = new SupplierTicketRecord();
                $copyLink->tickets = $copyTicket;
                $copyLink->actor = $sourceSupplier;
                foreach ([$copyTicket, $sourceSupplier, $copyLink] as $record) {
                    $copyManager->persist($record);
                }
                $copyManager->flush();
                $copyHookCalls = 0;
                $PLUGIN_HOOKS['pre_item_add']['transfer_itil_fixture'][SupplierModel::class] = function (SupplierModel $item) use ($target, &$copyHookCalls): void {
                    ++$copyHookCalls;
                    $this->boolean($item->isEntityAssign())->isTrue();
                    $this->integer((int)$item->input['entities_id'])->isIdenticalTo($target);
                };
                $this->boolean((new LegacyTransfer())->moveItems(['Ticket' => [$copyTicket->id]], $target, []))->isTrue();
                $this->integer($copyHookCalls)->isIdenticalTo(1);
                $copiedSupplierID = (int)$connection->fetchOne('SELECT suppliers_id FROM glpi_suppliers_tickets WHERE id=?', [$copyLink->id]);
                $this->integer($copiedSupplierID)->isGreaterThan(0)->isNotIdenticalTo($sourceSupplier->id);
                $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_suppliers WHERE id=?', [$copiedSupplierID]))->isIdenticalTo($target);
                $this->string($connection->fetchOne('SELECT name FROM glpi_suppliers WHERE id=?', [$copiedSupplierID]))->isIdenticalTo($sourceSupplier->name);
                $copyManager->clear();
                foreach ([false, true] as $refuse) {
                    $PLUGIN_HOOKS = $savedHooks;
                    $manager = Orm::create($database);
                    $ticket = new Ticket();
                    $ticket->entities = $manager->getReference(Entity::class, $source);
                    $ticket->name = 'Per-link current lookup ' . $this->getUniqueString();
                    $manager->persist($ticket);
                    $suppliers = $destinations = $links = [];
                    foreach ([0, 1] as $index) {
                        $name = 'Per-link supplier ' . $this->getUniqueString();
                        $supplier = new Supplier();
                        $supplier->entities = $manager->getReference(Entity::class, $source);
                        $supplier->name = $name;
                        $manager->persist($supplier);
                        $suppliers[] = $supplier;
                        $destination = new Supplier();
                        $destination->entities = $manager->getReference(Entity::class, $target);
                        $destination->name = $name;
                        $manager->persist($destination);
                        $destinations[] = $destination;
                        $link = new SupplierTicketRecord();
                        $link->tickets = $ticket;
                        $link->actor = $supplier;
                        $manager->persist($link);
                        $links[] = $link;
                    }
                    $manager->flush();
                    $calls = 0;
                    $trace = [];
                    $observedParent = null;
                    $notifications = $GLOBALS['CFG_GLPI']['use_notifications'];
                    $PLUGIN_HOOKS['item_update']['transfer_itil_fixture']['Ticket'] = function ($item) use ($connection, $ticket, $links, $suppliers, $target, &$trace, &$observedParent): void {
                        if ((int)$item->getID() !== $ticket->id) {
                            return;
                        }
                        $trace[] = 'parent';
                        $observedParent = $item;
                        $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_tickets WHERE id=?', [$ticket->id]))->isIdenticalTo($target);
                        foreach ($links as $index => $link) {
                            $this->integer((int)$connection->fetchOne('SELECT suppliers_id FROM glpi_suppliers_tickets WHERE id=?', [$link->id]))->isIdenticalTo($suppliers[$index]->id);
                        }
                        $this->integer($GLOBALS['CFG_GLPI']['use_notifications'])->isIdenticalTo(0);
                    };
                    $PLUGIN_HOOKS['item_transfer']['transfer_itil_fixture'] = function (array $event) use ($connection, $ticket, $links, $destinations, $target, &$trace, &$observedParent): void {
                        if ($event['type'] !== 'Ticket' || (int)$event['id'] !== $ticket->id) {
                            return;
                        }
                        $trace[] = 'terminal';
                        $this->object($observedParent);
                        $this->array(array_map('intval', array_column($observedParent->getSuppliers($links[0]->type), 'suppliers_id')))->contains($destinations[0]->id)->contains($destinations[1]->id)->hasSize(2);
                        $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_tickets WHERE id=?', [$ticket->id]))->isIdenticalTo($target);
                        foreach ($links as $link) {
                            $this->integer((int)$connection->fetchOne(
                                'SELECT supplier.entities_id FROM glpi_suppliers_tickets binding JOIN glpi_suppliers supplier ON supplier.id=binding.suppliers_id WHERE binding.id=?',
                                [$link->id]
                            ))->isIdenticalTo($target);
                        }
                        $this->integer($GLOBALS['CFG_GLPI']['use_notifications'])->isIdenticalTo(0);
                    };
                    $PLUGIN_HOOKS['item_update']['transfer_itil_fixture'][SupplierTicketModel::class] = static function (SupplierTicketModel $item) use ($connection, $links, $suppliers, $destinations, &$calls, &$trace): void {
                        ++$calls;
                        $trace[] = 'link';
                        if ($calls === 1) {
                            $other = (int)$item->getID() === $links[0]->id ? 1 : 0;
                            $name = 'Current callback supplier ' . $links[$other]->id;
                            $connection->update('glpi_suppliers', ['name' => $name], ['id' => $suppliers[$other]->id]);
                            $connection->update('glpi_suppliers', ['name' => $name], ['id' => $destinations[$other]->id]);
                        }
                    };
                    $PLUGIN_HOOKS['pre_item_update']['transfer_itil_fixture'][SupplierTicketModel::class] = function (SupplierTicketModel $item) use ($refuse, &$calls, $connection, $target): void {
                        $this->integer((int)$connection->fetchOne(
                            'SELECT entities_id FROM glpi_tickets WHERE id=?',
                            [$item->input['tickets_id']]
                        ))->isIdenticalTo($target);
                        $this->integer((int)$connection->fetchOne(
                            'SELECT entities_id FROM glpi_suppliers WHERE id=?',
                            [$item->input['suppliers_id']]
                        ))->isIdenticalTo($target);
                        if ($refuse && $calls === 1) {
                            $item->input = false;
                        }
                    };
                    $tables = ['glpi_tickets', 'glpi_suppliers', 'glpi_suppliers_tickets', 'glpi_logs', 'glpi_queuednotifications'];
                    $before = [];
                    foreach ($tables as $table) {
                        $before[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
                    }
                    $level = $connection->getTransactionNestingLevel();
                    $transfer = new LegacyTransfer();
                    $failure = null;
                    try {
                        $result = $transfer->moveItems(['Ticket' => [$ticket->id]], $target, []);
                    } catch (RuntimeException $error) {
                        if (!$refuse || $error->getFile() !== realpath(__DIR__ . '/../../inc/toolbox.class.php')
                            || !str_contains($error->getMessage(), 'Transfer::runTransfer()')
                            || !str_contains($error->getMessage(), 'Transfer refused: Supplier_Ticket update')) {
                            throw $error;
                        }
                        $failure = $error;
                    }
                    if ($refuse) {
                        $this->object($failure)->isInstanceOf(RuntimeException::class);
                        $this->integer($calls)->isIdenticalTo(1);
                        $this->array($trace)->isIdenticalTo(['parent', 'link']);
                        foreach ($tables as $table) {
                            $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id'))->isIdenticalTo($before[$table]);
                        }
                        $this->array($transfer->already_transfer)->isEmpty();
                    } else {
                        $this->boolean($result)->isTrue();
                        $this->integer($calls)->isIdenticalTo(2);
                        $this->array($trace)->isIdenticalTo(['parent', 'link', 'link', 'terminal']);
                        foreach ($links as $index => $link) {
                            $this->integer((int)$connection->fetchOne('SELECT suppliers_id FROM glpi_suppliers_tickets WHERE id=?', [$link->id]))->isIdenticalTo($destinations[$index]->id);
                        }
                        $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_tickets WHERE id=?', [$ticket->id]))->isIdenticalTo($target);
                    }
                    $this->variable($GLOBALS['CFG_GLPI']['use_notifications'])->isIdenticalTo($notifications);
                    $this->array($connection->fetchAllAssociative('SELECT * FROM glpi_queuednotifications ORDER BY id'))->isIdenticalTo($before['glpi_queuednotifications']);
                    $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
                    $manager->clear();
                }
            });
        } finally {
            $PLUGIN_HOOKS = $savedHooks;
            $plugins->setValue(null, $savedPlugins);
        }
    }

    public function testItilTransferProjectionCapturesCustomRouteAndRejectsIncoherentMetadata(): void
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $this->withSoftwareOwnerQueryProbe(function ($database, $connection): void {
            global $DB;
            $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $manager = Orm::create($database);
            $ticket = new Ticket();
            $ticket->entities = $manager->getReference(Entity::class, $source);
            $supplier = new Supplier();
            $supplier->entities = $manager->getReference(Entity::class, $source);
            $link = new SupplierTicketRecord();
            $link->tickets = $ticket;
            $link->actor = $supplier;
            foreach ([$ticket, $supplier, $link] as $record) {
                $manager->persist($record);
            }
            $manager->flush();
            $listener = new class () {
                public bool $malformed = false;
                public int $clears = 0;

                public function loadClassMetadata($event): void
                {
                    $metadata = $event->getClassMetadata();
                    if ($this->malformed && $metadata->name === SupplierTicketRecord::class) {
                        $metadata->setPrimaryTable(['name' => 'incoherent_transfer_fixture']);
                    }
                }

                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $events = new EventManager();
            $events->addEventListener(['loadClassMetadata', 'onClear'], $listener);
            $selected = new class ($connection, $events, $database) extends ScalarReadProbe {
                public int $constructions = 0;

                public function __construct($connection, private EventManager $events, private $replacement)
                {
                    parent::__construct($connection);
                }

                public function getEventManager(): EventManager
                {
                    ++$this->constructions;
                    $GLOBALS['DB'] = $this->replacement;
                    return $this->events;
                }
            };
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new NetpointRouteAdapter();
            $this->calling($adapter)->getDoctrineConnection = $selected;
            $this->calling($adapter)->getProvider = $database->getProvider();
            try {
                $DB = $adapter;
                [$field, $rows] = Orm::read($DB, static fn (EntityManager $owner): array =>
                    (new ITILActorRepository($owner))->suppliersForTransfer(new SupplierTicketModel(), 'Ticket', [$ticket->id]));
                $this->integer($selected->constructions)->isIdenticalTo(1);
                $this->array($selected->queries)->isNotEmpty();
                $this->string($field)->isIdenticalTo('tickets_id');
                $this->array($rows)->isIdenticalTo([['id' => $link->id, 'suppliers_id' => $supplier->id]]);
                $this->object($DB)->isIdenticalTo($database);
                $listener->malformed = true;
                $selected->queries = [];
                $DB = $adapter;
                $this->exception(static fn () => Orm::read($adapter, static fn (EntityManager $owner): array =>
                    (new ITILActorRepository($owner))->suppliersForTransfer(new SupplierTicketModel(), 'Ticket', $ticket->id)))
                    ->isInstanceOf(LogicException::class)->hasMessage('Incoherent supplier transfer relation');
                $this->array($selected->queries)->isEmpty();
                $this->integer($selected->constructions)->isIdenticalTo(2);
                $this->integer($listener->clears)->isIdenticalTo(0);
                $listener->malformed = false;
                $originalTable = SupplierTicketModel::getTable();
                try {
                    SupplierTicketModel::forceTable($manager->getClassMetadata(ChangeSupplierRecord::class)->getTableName());
                    $selected->queries = [];
                    $this->exception(static fn () => Orm::read($adapter, static fn (EntityManager $owner): array =>
                        (new ITILActorRepository($owner))->suppliersForTransfer(new SupplierTicketModel(), 'Ticket', $ticket->id)))
                        ->isInstanceOf(LogicException::class)->hasMessage('ITIL actor relation requires exactly one mapped parent reference');
                    $this->array($selected->queries)->isEmpty();
                } finally {
                    SupplierTicketModel::forceTable($originalTable);
                }
            } finally {
                $DB = $database;
            }
        });
    }

    public function testSharedPrinterTransferCopiesRequiredPortChildren(): void
    {
        global $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $savedHooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        try {
            $plugins->setValue(null, [...$savedPlugins, 'transfer_port_copy_fixture']);
            $this->withSoftwareOwnerQueryProbe(function ($database, $connection) use ($savedHooks): void {
                global $PLUGIN_HOOKS;
                $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
                $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
                foreach ([[1, false, false, true], [2, false, false, true], [1, true, false, true], [2, true, false, true], [2, false, true, true], [1, true, false, false], [2, true, false, false]] as [$keep, $empty, $refuse, $typed]) {
                    $PLUGIN_HOOKS = $savedHooks;
                    $computer = $this->createItem(Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => $source]);
                    $remaining = $this->createItem(Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => $source]);
                    $printer = $this->createItem(PrinterAsset::class, [
                        'name' => $this->getUniqueString(), 'entities_id' => $source, 'is_global' => 1, 'is_recursive' => 0,
                    ]);
                    foreach ([$computer, $remaining] as $owner) {
                        $this->createItem(ComputerItemModel::class, [
                            'computers_id' => $owner->getID(), 'itemtype' => 'Printer', 'items_id' => $printer->getID(),
                        ]);
                    }
                    $outlet = $empty ? null : $this->createItem(NetpointModel::class, [
                        'name' => $this->getUniqueString(), 'entities_id' => $source,
                    ]);
                    $port = new NetworkPortModel();
                    $portID = (int)$port->add([
                        'name' => $this->getUniqueString(), 'itemtype' => 'Printer', 'items_id' => $printer->getID(),
                        'netpoints_id' => $outlet?->getID() ?? 0, '_create_children' => true,
                    ] + ($typed ? ['instantiation_type' => 'NetworkPortEthernet'] : []));
                    $this->integer($portID)->isGreaterThan(0);
                    $this->boolean($port->getFromDB($portID))->isTrue();
                    $sourcePort = $connection->fetchAssociative('SELECT * FROM glpi_networkports WHERE id=?', [$portID]);
                    $sourceChild = $connection->fetchAssociative('SELECT * FROM glpi_networkportethernets WHERE networkports_id=?', [$portID]);
                    if ($typed) {
                        $this->array($sourceChild)->isNotEmpty();
                    } else {
                        $this->variable($sourceChild)->isIdenticalTo(false);
                        $this->variable($port->getField('instantiation_type'))->isNull();
                    }
                    $events = [];
                    $refusedPort = null;
                    $PLUGIN_HOOKS['pre_item_add']['transfer_port_copy_fixture'][EthernetModel::class] = static function (EthernetModel $child) use (&$events, &$refusedPort, $refuse): void {
                        $events[] = 'child-prepare';
                        if ($refuse) {
                            $refusedPort = $child->input['networkports_id'];
                            $child->input = false;
                        }
                    };
                    $PLUGIN_HOOKS['item_empty']['transfer_port_copy_fixture'][EthernetModel::class] = static function (EthernetModel $child) use (&$refusedPort): void {
                        if ($refusedPort !== null) {
                            // An empty-model hook cannot substitute for a successful persisted child load.
                            $child->fields['networkports_id'] = $refusedPort;
                        }
                    };
                    $PLUGIN_HOOKS['item_add']['transfer_port_copy_fixture'][EthernetModel::class] = static function () use (&$events): void {
                        $events[] = 'child-add';
                    };
                    $PLUGIN_HOOKS['item_add']['transfer_port_copy_fixture'][NetworkPortModel::class] = static function () use (&$events): void {
                        $events[] = 'parent-add';
                    };
                    $tables = ['glpi_computers', 'glpi_printers', 'glpi_computers_items', 'glpi_networkports', 'glpi_networkportethernets', 'glpi_netpoints'];
                    $before = [];
                    foreach ($tables as $table) {
                        $before[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
                    }
                    $manager = Orm::create($database);
                    $dirty = $manager->find(ComputerEntity::class, $remaining->getID());
                    $dirty->name = 'Pending computer outside transfer';
                    $level = $connection->getTransactionNestingLevel();
                    $transfer = new LegacyTransfer();
                    $failure = null;
                    $result = null;
                    try {
                        $result = $transfer->moveItems(['Computer' => [$computer->getID()]], $target, [
                            'keep_dc_printer' => 1, 'keep_networklink' => $keep, 'clean_dc_printer' => 0,
                        ]);
                    } catch (RuntimeException $error) {
                        if (!$refuse
                            || $error->getFile() !== realpath(__DIR__ . '/../../inc/toolbox.class.php')
                            || !str_contains($error->getMessage(), 'Transfer::runTransfer()')
                            || !str_contains($error->getMessage(), 'Copied network port instantiation was not created')) {
                            throw $error;
                        }
                        $failure = $error;
                    }
                    $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
                    $this->boolean($manager->contains($dirty))->isTrue();
                    $this->string($dirty->name)->isIdenticalTo('Pending computer outside transfer');
                    if ($refuse) {
                        $this->object($failure)->isInstanceOf(RuntimeException::class);
                        $this->string($failure->getFile())->isIdenticalTo(realpath(__DIR__ . '/../../inc/toolbox.class.php'));
                        $this->string($failure->getMessage())->contains('Transfer::runTransfer()')
                            ->contains('Copied network port instantiation was not created');
                        $this->variable($result)->isNull();
                        $this->array($events)->isIdenticalTo(['child-prepare', 'parent-add']);
                        foreach ($tables as $table) {
                            $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id'))->isIdenticalTo($before[$table]);
                        }
                        $this->array($transfer->already_transfer)->isEmpty();
                    } else {
                        $this->boolean($result)->isTrue();
                        $this->array($events)->isIdenticalTo($typed ? ['child-prepare', 'child-add', 'parent-add'] : ['parent-add']);
                        $copyID = (int)$connection->fetchOne('SELECT items_id FROM glpi_computers_items WHERE computers_id=? AND itemtype=?', [$computer->getID(), 'Printer']);
                        $this->integer($copyID)->isGreaterThan(0)->isNotIdenticalTo((int)$printer->getID());
                        $copiedPrinter = new PrinterAsset();
                        $this->boolean($copiedPrinter->getFromDB($copyID))->isTrue();
                        $this->integer((int)$copiedPrinter->getEntityID())->isIdenticalTo($target);
                        $copies = $connection->fetchAllAssociative('SELECT * FROM glpi_networkports WHERE items_id=? AND itemtype=?', [$copyID, 'Printer']);
                        $this->array($copies)->hasSize(1);
                        $copiedPortID = (int)$copies[0]['id'];
                        $this->integer((int)$copies[0]['entities_id'])->isIdenticalTo($target);
                        $this->integer((int)$copies[0]['is_recursive'])->isIdenticalTo((int)$copiedPrinter->isRecursive());
                        $copiedPort = new NetworkPortModel();
                        $this->boolean($copiedPort->getFromDB($copiedPortID))->isTrue();
                        $child = $copiedPort->getInstantiation();
                        if ($typed) {
                            $this->boolean($child !== false && !$child->isNewID($child->getID()))->isTrue();
                            $this->integer((int)$child->getID())->isIdenticalTo($copiedPortID);
                            if ($empty) {
                                $this->variable($connection->fetchOne('SELECT netpoints_id FROM glpi_networkportethernets WHERE networkports_id=?', [$copiedPortID]))->isNull();
                            } else {
                                $outletID = (int)$child->getField('netpoints_id');
                                $this->integer($outletID)->isGreaterThan(0)->isNotIdenticalTo((int)$outlet->getID());
                                $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_netpoints WHERE id=?', [$outletID]))->isIdenticalTo($target);
                            }
                        } else {
                            $this->variable($child)->isIdenticalTo(false);
                            $this->variable($copiedPort->getField('instantiation_type'))->isNull();
                            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_networkportethernets WHERE networkports_id=?', [$copiedPortID]))->isIdenticalTo(0);
                        }
                        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_networkports_networkports WHERE networkports_id_1=? OR networkports_id_2=?', [$copiedPortID, $copiedPortID]))->isIdenticalTo(0);
                    }
                    $this->array($connection->fetchAssociative('SELECT * FROM glpi_networkports WHERE id=?', [$portID]))->isIdenticalTo($sourcePort);
                    $this->variable($connection->fetchAssociative('SELECT * FROM glpi_networkportethernets WHERE networkports_id=?', [$portID]))->isIdenticalTo($sourceChild);
                    $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_printers WHERE id=?', [$printer->getID()]))->isIdenticalTo($source);
                    $manager->clear();
                }
            });
        } finally {
            $PLUGIN_HOOKS = $savedHooks;
            $plugins->setValue(null, $savedPlugins);
        }
    }

    public function testNetworkTransferReusesCurrentScopedOutletAndKeepsLiveOwners(): void
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $this->withSoftwareOwnerQueryProbe(function ($database, $connection): void {
            $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
            foreach ([['NULL', false, 2], ['null', true, 1], ["Outlet'\\north", false, 2]] as [$name, $located, $keep]) {
                $sourceLocation = $located
                    ? $this->createItem(LocationModel::class, ['name' => $this->getUniqueString(), 'entities_id' => $source]) : null;
                $targetLocation = $located
                    ? $this->createItem(LocationModel::class, ['name' => $sourceLocation->getField('name'), 'entities_id' => $target]) : null;
                $wrongLocation = $this->createItem(LocationModel::class, ['name' => $this->getUniqueString(), 'entities_id' => $target]);
                $outlet = $this->createItem(NetpointModel::class, [
                    'name' => addslashes($name), 'comment' => 'Retain source outlet', 'entities_id' => $source,
                    'locations_id' => $sourceLocation?->getID() ?? 0,
                ]);
                $candidates = [];
                foreach ([0, 1] as $unused) {
                    $candidates[] = $this->createItem(NetpointModel::class, [
                        'name' => $this->getUniqueString(), 'entities_id' => $target,
                        'locations_id' => $targetLocation?->getID() ?? 0,
                    ]);
                }
                $this->createItem(NetpointModel::class, [
                    'name' => addslashes($name), 'entities_id' => $target, 'locations_id' => $wrongLocation->getID(),
                ]);
                $decoy = $this->createItem(NetpointModel::class, [
                    'name' => $this->getUniqueString(), 'entities_id' => $target,
                    'locations_id' => $targetLocation?->getID() ?? 0,
                ]);
                $connection->update('glpi_netpoints', ['name' => null], ['id' => $decoy->getID()]);
                $computer = $this->createItem(Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => $source]);
                $port = new NetworkPortModel();
                $portInput = [
                    'name' => $this->getUniqueString(), 'items_id' => $computer->getID(), 'itemtype' => 'Computer',
                    'entities_id' => $source, 'instantiation_type' => 'NetworkPortEthernet',
                ];
                $portID = (int)$port->add($portInput + ['netpoints_id' => $outlet->getID(), '_create_children' => true]);
                $this->checkInput($port, $portID, $portInput);
                $ethernet = new EthernetModel();
                $this->boolean($ethernet->getFromDBByCrit(['networkports_id' => $portID]))->isTrue();
                $this->integer((int)$ethernet->getField('netpoints_id'))->isIdenticalTo((int)$outlet->getID());
                $wire = null;
                if ($keep === 1) {
                    $peerComputer = $this->createItem(Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => $source]);
                    $peerPort = $this->createItem(NetworkPortModel::class, [
                        'name' => $this->getUniqueString(), 'items_id' => $peerComputer->getID(), 'itemtype' => 'Computer',
                        'entities_id' => $source, 'instantiation_type' => 'NetworkPortEthernet',
                    ]);
                    $wire = $this->createItem(PortConnectionModel::class, [
                        'networkports_id_1' => $portID, 'networkports_id_2' => $peerPort->getID(),
                    ]);
                }
                $sourceRow = $connection->fetchAssociative('SELECT * FROM glpi_netpoints WHERE id=?', [$outlet->getID()]);
                $sourceLocationRow = $sourceLocation === null ? null
                    : $connection->fetchAssociative('SELECT * FROM glpi_locations WHERE id=?', [$sourceLocation->getID()]);
                $owner = Orm::create($database);
                $dirty = $owner->find(NetpointRecord::class, $candidates[0]->getID());
                $dirty->name = 'Unflushed destination outlet';
                foreach ($candidates as $candidate) {
                    $connection->update('glpi_netpoints', ['name' => $name], ['id' => $candidate->getID()]);
                }
                $before = $connection->fetchOne('SELECT COUNT(*) FROM glpi_netpoints WHERE entities_id=?', [$target]);
                $level = $connection->getTransactionNestingLevel();
                $transfer = new LegacyTransfer();
                Orm::read($database, function (EntityManager $outer) use ($transfer, $computer, $target, $candidates, $keep): void {
                    $dirty = $outer->find(NetpointRecord::class, $candidates[1]->getID());
                    $dirty->comment = 'Unflushed nested outlet';
                    $this->boolean($transfer->moveItems(
                        ['Computer' => [$computer->getID()]],
                        $target,
                        ['keep_networklink' => $keep]
                    ))->isTrue();
                    $this->boolean($outer->contains($dirty))->isTrue();
                    $this->string($dirty->comment)->isIdenticalTo('Unflushed nested outlet');
                });
                $destination = (int)$connection->fetchOne('SELECT netpoints_id FROM glpi_networkportethernets WHERE networkports_id=?', [$portID]);
                $this->array(array_map(static fn ($candidate): int => (int)$candidate->getID(), $candidates))->contains($destination);
                if ($wire !== null) {
                    $this->boolean($wire->getFromDB($wire->getID()))->isFalse();
                    $this->boolean($peerPort->getFromDB($peerPort->getID()))->isTrue();
                    $this->integer((int)$peerPort->getField('entities_id'))->isIdenticalTo($source);
                }
                $this->integer((int)$transfer->already_transfer['netpoints_id'][$outlet->getID()])->isIdenticalTo($destination);
                $this->variable($connection->fetchOne('SELECT COUNT(*) FROM glpi_netpoints WHERE entities_id=?', [$target]))->isIdenticalTo($before);
                $this->array($connection->fetchAssociative('SELECT * FROM glpi_netpoints WHERE id=?', [$outlet->getID()]))->isIdenticalTo($sourceRow);
                if ($sourceLocation !== null) {
                    $this->array($connection->fetchAssociative('SELECT * FROM glpi_locations WHERE id=?', [$sourceLocation->getID()]))->isIdenticalTo($sourceLocationRow);
                }
                $this->variable($connection->fetchOne('SELECT locations_id FROM glpi_netpoints WHERE id=?', [$destination]))
                    ->isEqualTo($targetLocation?->getID());
                $this->boolean($owner->contains($dirty))->isTrue();
                $this->string($dirty->name)->isIdenticalTo('Unflushed destination outlet');
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
                $owner->clear();
            }
        });
    }

    public function testNetworkOutletCopyHooksRollbackAndSelectedCallbackRoute(): void
    {
        global $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $savedHooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        try {
            $plugins->setValue(null, [...$savedPlugins, 'transfer_netpoint_fixture']);
            $this->withSoftwareOwnerQueryProbe(function ($database, $connection) use ($savedHooks): void {
                global $DB, $PLUGIN_HOOKS;
                $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
                $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
                foreach ([['NULL', false, null], ['null', false, null], [null, false, null], [$this->getUniqueString(), true, null],
                    [$this->getUniqueString(), false, 'refusal'], [$this->getUniqueString(), false, 'throw']] as [$name, $refuse, $childFailure]) {
                    $PLUGIN_HOOKS = $savedHooks;
                    $location = $this->createItem(LocationModel::class, ['name' => $this->getUniqueString(), 'entities_id' => $source]);
                    $outlet = new NetpointModel();
                    $outletID = (int)$outlet->add([
                        'name' => $name === null ? null : addslashes($name), 'comment' => 'Copied outlet comment',
                        'entities_id' => $source, 'locations_id' => $location->getID(),
                    ]);
                    $this->integer($outletID)->isGreaterThan(0);
                    $this->boolean($outlet->getFromDB($outletID))->isTrue();
                    $this->variable($connection->fetchOne('SELECT name FROM glpi_netpoints WHERE id=?', [$outletID]))->isIdenticalTo($name);
                    $computer = $this->createItem(Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => $source]);
                    $port = new NetworkPortModel();
                    $portID = (int)$port->add([
                        'name' => $this->getUniqueString(), 'items_id' => $computer->getID(), 'itemtype' => 'Computer',
                        'entities_id' => $source, 'instantiation_type' => 'NetworkPortEthernet',
                        'netpoints_id' => $outletID, '_create_children' => true,
                    ]);
                    $this->integer($portID)->isGreaterThan(0);
                    $this->boolean($port->getFromDB($portID))->isTrue();
                    $ethernet = new EthernetModel();
                    $this->boolean($ethernet->getFromDBByCrit(['networkports_id' => $portID]))->isTrue();
                    $this->integer((int)$ethernet->getField('netpoints_id'))->isIdenticalTo($outletID);
                    $observed = null;
                    $PLUGIN_HOOKS['item_add']['transfer_netpoint_fixture'][NetpointModel::class] = static function (NetpointModel $item) use ($target, $refuse, &$observed): void {
                        if ((int)$item->getField('entities_id') !== $target) {
                            return;
                        }
                        $observed = $item->fields;
                        if ($refuse) {
                            throw new RuntimeException('Refused owned outlet copy');
                        }
                    };
                    $childCalls = 0;
                    $childDestination = null;
                    $persistedDestination = null;
                    if ($childFailure !== null) {
                        $PLUGIN_HOOKS['pre_item_update']['transfer_netpoint_fixture'][EthernetModel::class] = static function (EthernetModel $item) use ($portID, $childFailure, &$childCalls, &$childDestination): void {
                            if ((int)$item->getID() !== $portID) {
                                return;
                            }
                            ++$childCalls;
                            $childDestination = (int)$item->input['netpoints_id'];
                            if ($childFailure === 'refusal') {
                                $item->input = false;
                            }
                        };
                        $PLUGIN_HOOKS['item_update']['transfer_netpoint_fixture'][EthernetModel::class] = static function (EthernetModel $item) use ($portID, $connection, &$persistedDestination): void {
                            if ((int)$item->getID() === $portID) {
                                $persistedDestination = (int)$connection->fetchOne('SELECT netpoints_id FROM glpi_networkportethernets WHERE networkports_id=?', [$portID]);
                                throw new RuntimeException('Refused persisted outlet child update');
                            }
                        };
                    }
                    $tables = ['glpi_computers', 'glpi_networkports', 'glpi_networkportethernets', 'glpi_netpoints', 'glpi_locations'];
                    $before = [];
                    foreach ($tables as $table) {
                        $before[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
                    }
                    $level = $connection->getTransactionNestingLevel();
                    $transfer = new LegacyTransfer();
                    $failure = null;
                    $result = null;
                    $diagnostic = $refuse ? 'Refused owned outlet copy'
                            : ($childFailure === 'refusal' ? 'Transfer refused: NetworkPortEthernet update' : 'Refused persisted outlet child update');
                    try {
                        $result = $transfer->moveItems(['Computer' => [$computer->getID()]], $target, ['keep_networklink' => 2]);
                    } catch (RuntimeException $error) {
                        if ((!$refuse && $childFailure === null)
                            || $error->getFile() !== realpath(__DIR__ . '/../../inc/toolbox.class.php')
                            || !str_contains($error->getMessage(), 'Transfer::runTransfer()')
                            || !str_contains($error->getMessage(), $diagnostic)) {
                            throw $error;
                        }
                        $failure = $error;
                    }
                    $this->array($observed)->isNotEmpty();
                    $this->variable($observed['name'])->isIdenticalTo($name);
                    $this->string($observed['comment'])->isIdenticalTo('Copied outlet comment');
                    $this->integer((int)$observed['entities_id'])->isIdenticalTo($target);
                    if ($refuse || $childFailure !== null) {
                        $this->object($failure)->isInstanceOf(RuntimeException::class);
                        $this->string($failure->getFile())->isIdenticalTo(realpath(__DIR__ . '/../../inc/toolbox.class.php'));
                        $this->string($failure->getMessage())->contains('Transfer::runTransfer()')->contains($diagnostic);
                        $this->variable($result)->isNull();
                        if ($childFailure !== null) {
                            $this->integer($childCalls)->isIdenticalTo(1);
                            $this->integer($childDestination)->isGreaterThan(0)->isNotIdenticalTo($outletID);
                            $this->integer($childDestination)->isIdenticalTo((int)$observed['id']);
                            if ($childFailure === 'throw') {
                                $this->integer($persistedDestination)->isIdenticalTo($childDestination);
                            } else {
                                $this->variable($persistedDestination)->isNull();
                            }
                        }
                        foreach ($tables as $table) {
                            $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id'))->isIdenticalTo($before[$table]);
                        }
                        $this->array($transfer->already_transfer)->isEmpty();
                    } else {
                        $this->boolean($result)->isTrue();
                        $copy = (int)$connection->fetchOne('SELECT netpoints_id FROM glpi_networkportethernets WHERE networkports_id=?', [$portID]);
                        $this->integer($copy)->isGreaterThan(0)->isNotIdenticalTo($outletID);
                        $this->variable($connection->fetchOne('SELECT name FROM glpi_netpoints WHERE id=?', [$copy]))->isIdenticalTo($name);
                        $this->string($connection->fetchOne('SELECT comment FROM glpi_netpoints WHERE id=?', [$copy]))->isIdenticalTo('Copied outlet comment');
                        $newLocation = (int)$connection->fetchOne('SELECT locations_id FROM glpi_netpoints WHERE id=?', [$copy]);
                        $this->integer($newLocation)->isGreaterThan(0)->isNotIdenticalTo((int)$location->getID());
                        $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_locations WHERE id=?', [$newLocation]))->isIdenticalTo($target);
                        $beforeCount = $connection->fetchOne('SELECT COUNT(*) FROM glpi_netpoints');
                        $this->integer((int)$transfer->transferDropdownNetpoint($outletID))->isIdenticalTo($copy);
                        $fresh = new LegacyTransfer();
                        $fresh->to = $target;
                        $this->integer((int)$fresh->transferDropdownNetpoint($outletID))->isIdenticalTo($copy);
                        $this->integer((int)$fresh->already_transfer['netpoints_id'][$outletID])->isIdenticalTo($copy);
                        $this->variable($connection->fetchOne('SELECT COUNT(*) FROM glpi_netpoints'))->isIdenticalTo($beforeCount);
                        $this->integer((int)$transfer->already_transfer['netpoints_id'][$outletID])->isIdenticalTo($copy);
                        $this->array($connection->fetchAssociative('SELECT * FROM glpi_netpoints WHERE id=?', [$outletID]))
                            ->isIdenticalTo(array_values(array_filter($before['glpi_netpoints'], static fn (array $row): bool => (int)$row['id'] === $outletID))[0]);
                    }
                    $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
                }
                $PLUGIN_HOOKS = $savedHooks;
                $locationName = $this->getUniqueString();
                $sourceLocation = $this->createItem(LocationModel::class, ['name' => $locationName, 'entities_id' => $source]);
                $targetLocation = $this->createItem(LocationModel::class, ['name' => $locationName, 'entities_id' => $target]);
                $outlet = $this->createItem(NetpointModel::class, [
                    'name' => addslashes("Callback'\\route"), 'entities_id' => $source, 'locations_id' => $sourceLocation->getID(),
                ]);
                $destination = $this->createItem(NetpointModel::class, [
                    'name' => $this->getUniqueString(), 'entities_id' => $target, 'locations_id' => $targetLocation->getID(),
                ]);
                $selected = new class ($connection) extends ScalarReadProbe {
                    public function getEventManager(): EventManager
                    {
                        throw new RuntimeException('Fixed outlet lookup must not construct an EntityManager');
                    }
                };
                $this->mockGenerator()->orphanize('__construct');
                $adapter = new NetpointRouteAdapter();
                $this->calling($adapter)->getDoctrineConnection = $selected;
                $this->calling($adapter)->getProvider = $database->getProvider();
                $transfer = new class () extends LegacyTransfer {
                    public ?Closure $afterLocation = null;

                    public function transferDropdownLocation($locID)
                    {
                        $id = parent::transferDropdownLocation($locID);
                        ($this->afterLocation)();
                        return $id;
                    }
                };
                $transfer->to = $target;
                $transfer->afterLocation = static function () use ($connection, $outlet, $destination, $adapter): void {
                    $connection->update('glpi_netpoints', ['name' => $outlet->getField('name')], ['id' => $destination->getID()]);
                    $GLOBALS['DB'] = $adapter;
                };
                try {
                    // This public reuse helper has no coordinator; the test's actual outer frame owns cleanup.
                    $this->integer((int)$transfer->transferDropdownNetpoint($outlet->getID()))->isIdenticalTo((int)$destination->getID());
                    $this->integer((int)$transfer->already_transfer['netpoints_id'][$outlet->getID()])->isIdenticalTo((int)$destination->getID());
                    $this->array($selected->queries)->isNotEmpty();
                } finally {
                    $DB = $database;
                }
            });
        } finally {
            $PLUGIN_HOOKS = $savedHooks;
            $plugins->setValue(null, $savedPlugins);
        }
    }

    public function testTransfer()
    {
        $this->login();

        //Original entity
        $fentity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        //Destination entity
        $dentity = (int)getItemByTypeName('Entity', '_test_child_2', true);

        $location_id = getItemByTypeName('Location', '_location01', true);

        $itemtypeslist = $this->getClasses(
            'searchOptions',
            [
              '/^Rule.*/',
              '/^Common.*/',
              '/^DB.*/',
              '/^SlaLevel.*/',
              '/^OlaLevel.*/',
              'Reservation',
              'ReservationItem',
              'Event',
              'Glpi\\Event',
              'KnowbaseItem',
              'NetworkPortMigration',
              '/^TicketTemplate.*/',
              '/^Computer_Software.*/',
              '/SavedSearch.*/',
              '/.*Notification.*/',
              '/.*Cost.*/',
              '/^Item_.*/',
              '/^Device.*/',
              '/.*Validation$/',
              '/^Network.*/',
              'CalendarSegment',
              'IPAddress',
              'IPNetwork',
              'FQDN',
              '/^SoftwareVersion.*/',
              '/^SoftwareLicense.*/',
              '/.*Predefined.*/',
              '/.*Mandatory.*/',
              '/.*Hidden.*/',
              'Entity_Reminder',
              'Document_Item',
              'Cartridge',
              '/.*Task.*/',
              'Entity_RSSFeed',
              'ComputerVirtualMachine',
              'FieldUnicity',
              'PurgeLogs',
              '/.*_?KnowbaseItem_?.*/',
              'Consumable',
              'Infocom',
              'ComputerAntivirus',
              'TicketRecurrent'
         ]
        );

        $fields_values = [
           'name'            => 'Object to transfer',
           'entities_id'     => $fentity,
           'content'         => 'A content',
           'definition_time' => 'hour',
           'number_time'     => 4,
           'begin_date'      => '2020-01-01',
           'url'            => 'file://' . realpath(__DIR__ . '/../fixtures/rssfeed.xml'),
           'itemtype'       => 'Computer',
        ];

        $addParent = function (string $parentType, string $itemtype) use ($fields_values): int {
            $parent = new $parentType();
            $parentInput = [];
            foreach ($fields_values as $field => $value) {
                if ($parent->isField($field)) {
                    $parentInput[$field] = $value;
                }
            }
            $parentId = $parent->add($parentInput);
            $this->integer((int)$parentId)->isGreaterThan(0, "Cannot add required $parentType for $itemtype");
            return (int)$parentId;
        };

        $count = 0;
        foreach ($itemtypeslist as $itemtype) {
            if (!in_array($itemtype, ['Accessibility', 'Oidc'])) {
                $item_class = new \ReflectionClass($itemtype);
                if ($item_class->isAbstract()) {
                    continue;
                }

                $obj = new $itemtype();
                if (!$obj->isEntityAssign()) {
                    continue;
                }

                // Add
                $input = [];
                foreach ($fields_values as $field => $value) {
                    if ($obj->isField($field)) {
                        $input[$field] = $value;
                    }
                }

                if ($obj->maybeLocated()) {
                    $input['locations_id'] = $location_id;
                }

                if ($obj instanceof CommonDBRelation) {
                    // Fixed relation endpoints are required owners, rather
                    // than optional scalar defaults in this transfer fixture.
                    foreach ([[$obj::$itemtype_1, $obj::$items_id_1], [$obj::$itemtype_2, $obj::$items_id_2]] as [$parentType, $parentColumn]) {
                        if (!class_exists($parentType) || isset($input[$parentColumn])) {
                            continue;
                        }
                        $input[$parentColumn] = $addParent($parentType, $itemtype);
                    }
                }

                $entityClass = EntityRegistry::tables()[$obj::getTable()] ?? null;
                if ($entityClass !== null) {
                    $orm = Orm::create($GLOBALS['DB']);
                    foreach ($orm->getClassMetadata($entityClass)->associationMappings as $association) {
                        if (!$association->isToOneOwningSide()) {
                            continue;
                        }
                        foreach ($association->joinColumns as $join) {
                            if ($join->nullable || array_key_exists($join->name, $input)) {
                                continue;
                            }
                            $parentType = getItemTypeForTable($orm->getClassMetadata($association->targetEntity)->getTableName());
                            $input[$join->name] = $addParent($parentType, $itemtype);
                        }
                    }
                }

                $id = $obj->add($input);
                $this->integer((int)$id)->isGreaterThan(0, "Cannot add $itemtype");
                $this->boolean($obj->getFromDB($id))->isTrue();

                //transer to another entity
                $transfer = new \Transfer();

                $controller = new MockController();
                $controller->__construct = function () {
                    // void
                };

                $ma = new \mock\MassiveAction([], [], 'process', $controller);

                \MassiveAction::processMassiveActionsForOneItemtype(
                    $ma,
                    $obj,
                    [$id]
                );
                $owner = $obj;
                if (!$obj->isField('entities_id') && $obj instanceof CommonDBChild) {
                    $action = 'MassiveAction' . MassiveAction::CLASS_ACTION_SEPARATOR . 'add_transfer_list';
                    $this->array($obj->getSpecificMassiveActions())->notHasKey($action);
                    $owner = $obj->getItem();
                    $this->array($owner->getSpecificMassiveActions())->hasKey($action);
                }
                $ownerId = $owner->getID();
                $transfer->moveItems([$owner->getType() => [$ownerId]], $dentity, [$ownerId]);
                unset($_SESSION['glpitransfer_list']);

                $this->boolean($obj->getFromDB($id))->isTrue();
                $entity = $obj->isField('entities_id') ? $obj->fields['entities_id'] : $obj->getEntityID();
                $this->integer((int)$entity)->isidenticalTo($dentity, "Transfer has failed on $itemtype");
                if ($owner !== $obj) {
                    $this->integer((int)$obj->getItem()->getID())->isIdenticalTo((int)$ownerId);
                }

                ++$count;
            }
        }
        $this->dump(
            sprintf(
                '%1$s itemtypes tested',
                $count
            )
        );
    }

    public function testChildWithInheritedEntityOwnership()
    {
        $this->login();
        $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $destination = (int)getItemByTypeName('Entity', '_test_child_2', true);
        $link = new Link();
        $linkId = $link->add(['name' => 'Inherited transfer owner', 'entities_id' => $source]);
        $this->integer((int)$linkId)->isGreaterThan(0);
        $child = new Link_Itemtype();
        $childId = $child->add(['links_id' => $linkId, 'itemtype' => 'Computer']);
        $this->integer((int)$childId)->isGreaterThan(0);
        $this->boolean($child->getFromDB($childId))->isTrue();
        $this->boolean($child->isEntityAssign())->isTrue();
        $this->boolean($child->isField('entities_id'))->isFalse();
        $action = 'MassiveAction' . MassiveAction::CLASS_ACTION_SEPARATOR . 'add_transfer_list';
        $this->array($child->getSpecificMassiveActions())->notHasKey($action);
        $owner = $child->getItem();
        $this->integer((int)$owner->getID())->isIdenticalTo((int)$linkId);
        $this->array($owner->getSpecificMassiveActions())->hasKey($action);
        (new LegacyTransfer())->moveItems([$owner->getType() => [$owner->getID()]], $destination, [$owner->getID()]);
        unset($_SESSION['glpitransfer_list']);
        $this->boolean($child->getFromDB($childId))->isTrue();
        $this->integer((int)$child->fields['links_id'])->isIdenticalTo((int)$linkId);
        $this->integer((int)$child->getEntityID())->isIdenticalTo($destination);
        $this->boolean($link->getFromDB($linkId))->isTrue();
        $this->integer((int)$link->fields['entities_id'])->isIdenticalTo($destination);
    }

    public function testInstalledCartridgeTransferUsesCurrentModelsAndKeepsStock(): void
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $this->withSoftwareOwnerQueryProbe(function ($database, $connection): void {
            $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
            $manager = Orm::create($database);
            foreach (["Transfer O'Brien\\path", null, 'NuLl', 'NULL', 'null'] as $index => $name) {
                $type = new CartridgeItem();
                $type->entities = $manager->getReference(Entity::class, $source);
                $type->name = $name;
                $manager->persist($type);
                $destination = new CartridgeItem();
                $destination->entities = $manager->getReference(Entity::class, $target);
                $destination->name = $name === null ? '' : $name;
                $destination->is_deleted = true;
                $manager->persist($destination);
                if ($index === 0) {
                    $duplicate = new CartridgeItem();
                    $duplicate->entities = $manager->getReference(Entity::class, $target);
                    $duplicate->name = $name;
                    $manager->persist($duplicate);
                }
                $decoy = new CartridgeItem();
                $decoy->entities = $manager->getReference(Entity::class, $target);
                $decoy->name = null;
                $manager->persist($decoy);
                $printers = [];
                foreach (['Selected cartridge printer', 'Other cartridge printer'] as $printerName) {
                    $printer = new PrinterEntity();
                    $printer->entities = $manager->getReference(Entity::class, $source);
                    $printer->name = $printerName;
                    $manager->persist($printer);
                    $printers[] = $printer;
                }
                $cartridges = [];
                foreach ([$printers[0], $printers[1], null] as $printer) {
                    $cartridge = new Cartridge();
                    $cartridge->entities = $manager->getReference(Entity::class, $source);
                    $cartridge->cartridgeitems = $type;
                    $cartridge->printers = $printer;
                    $cartridge->date_out = new DateTime('2020-01-02');
                    $manager->persist($cartridge);
                    $cartridges[] = $cartridge;
                }
                $manager->flush();
                // A write after fixture creation must be seen without refreshing managed entities.
                $connection->update('glpi_cartridgeitems', ['comment' => 'Current destination'], ['id' => $destination->id]);
                $destination->comment = 'Unflushed independent comment';
                $expectedModels = array_map('intval', $connection->fetchFirstColumn(
                    'SELECT id FROM glpi_cartridgeitems WHERE entities_id=? AND name=?',
                    [$target, (string)$name]
                ));
                $run = function ($outer) use ($printers, $cartridges, $destination, $decoy, $type, $target, $source, $manager, $connection, $expectedModels): void {
                    $retained = $outer->find(CartridgeItem::class, $destination->id);
                    $retained->comment = 'Unflushed outer comment';
                    $this->boolean((new LegacyTransfer())->moveItems(
                        ['Printer' => [$printers[0]->id]],
                        $target,
                        ['keep_cartridgeitem' => 1, 'clean_cartridgeitem' => 2]
                    ))->isTrue();
                    $selectedModel = (int)$connection->fetchOne('SELECT cartridgeitems_id FROM glpi_cartridges WHERE id=?', [$cartridges[0]->id]);
                    $this->boolean(in_array($selectedModel, $expectedModels, true))->isTrue();
                    $this->integer($selectedModel)->isNotIdenticalTo($decoy->id);
                    $this->variable($connection->fetchOne('SELECT name FROM glpi_cartridgeitems WHERE id=?', [$decoy->id]))->isNull();
                    foreach ([$cartridges[1], $cartridges[2]] as $remaining) {
                        $this->integer((int)$connection->fetchOne('SELECT cartridgeitems_id FROM glpi_cartridges WHERE id=?', [$remaining->id]))
                            ->isIdenticalTo($type->id);
                    }
                    $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_cartridgeitems WHERE id=?', [$type->id]))->isIdenticalTo($source);
                    $this->string($connection->fetchOne('SELECT date_out FROM glpi_cartridges WHERE id=?', [$cartridges[0]->id]))->isIdenticalTo('2020-01-02');
                    $this->boolean($manager->contains($destination))->isTrue();
                    $this->string($destination->comment)->isIdenticalTo('Unflushed independent comment');
                    $this->boolean($outer->contains($retained))->isTrue();
                    $this->string($retained->comment)->isIdenticalTo('Unflushed outer comment');
                    $this->string($connection->fetchOne('SELECT comment FROM glpi_cartridgeitems WHERE id=?', [$destination->id]))->isIdenticalTo('Current destination');
                };
                $connection->withApplicationEntityManager($run);
                // Later flushes in this fixture must not publish intentionally retained edits.
                $manager->detach($destination);
            }
            // With no other installed printer, NULL-printer stock does not force a copy.
            $type = new CartridgeItem();
            $type->entities = $manager->getReference(Entity::class, $source);
            $type->name = 'Whole cartridge transfer';
            $manager->persist($type);
            $printer = new PrinterEntity();
            $printer->entities = $manager->getReference(Entity::class, $source);
            $manager->persist($printer);
            foreach ([$printer, null] as $parent) {
                $cartridge = new Cartridge();
                $cartridge->entities = $manager->getReference(Entity::class, $source);
                $cartridge->cartridgeitems = $type;
                $cartridge->printers = $parent;
                $manager->persist($cartridge);
            }
            $manager->flush();
            $this->boolean((new LegacyTransfer())->moveItems(['Printer' => [$printer->id]], $target, ['keep_cartridgeitem' => 1]))->isTrue();
            $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_cartridgeitems WHERE id=?', [$type->id]))->isIdenticalTo($target);
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_cartridges WHERE cartridgeitems_id=?', [$type->id]))->isIdenticalTo(2);
        });
    }

    public function testCartridgeCopyCompatibilityAndCleanupReadAfterHooks(): void
    {
        global $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $savedHooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        try {
            $plugins->setValue(null, [...$savedPlugins, 'transfer_cartridge_fixture']);
            $this->withSoftwareOwnerQueryProbe(function ($database, $connection) use ($savedHooks): void {
                global $PLUGIN_HOOKS;
                $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
                $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
                $manager = Orm::create($database);
                foreach ([false, true] as $refuse) {
                    $PLUGIN_HOOKS = $savedHooks;
                    $type = new CartridgeItem();
                    $type->entities = $manager->getReference(Entity::class, $source);
                    $type->name = $refuse ? 'Rollback cartridge copy' : 'NULL';
                    $type->comment = 'null';
                    $manager->persist($type);
                    $decoy = new CartridgeItem();
                    $decoy->entities = $manager->getReference(Entity::class, $target);
                    $decoy->name = null;
                    $manager->persist($decoy);
                    $model = new PrinterModel();
                    $model->name = $type->name;
                    $manager->persist($model);
                    $compatibility = new CartridgeItemPrinterModel();
                    $compatibility->cartridgeitems = $type;
                    $compatibility->printermodels = $model;
                    $manager->persist($compatibility);
                    $printers = [];
                    foreach ([0, 1] as $index) {
                        $printer = new PrinterEntity();
                        $printer->entities = $manager->getReference(Entity::class, $source);
                        $manager->persist($printer);
                        $printers[] = $printer;
                        $cartridge = new Cartridge();
                        $cartridge->entities = $manager->getReference(Entity::class, $source);
                        $cartridge->cartridgeitems = $type;
                        $cartridge->printers = $printer;
                        $manager->persist($cartridge);
                        if ($index === 0) {
                            $selected = $cartridge;
                        }
                    }
                    $manager->flush();
                    $fired = false;
                    $PLUGIN_HOOKS['item_update']['transfer_cartridge_fixture'][LegacyCartridge::class] = static function (LegacyCartridge $item) use ($selected, $type, $source, $connection, $refuse, &$fired): void {
                        if ((int)$item->getID() !== $selected->id) {
                            return;
                        }
                        $fired = true;
                        // These current writes occur after retargeting, before the cleanup count.
                        $connection->delete('glpi_cartridges', ['cartridgeitems_id' => $type->id]);
                        $connection->insert('glpi_cartridges', ['cartridgeitems_id' => $type->id, 'entities_id' => $source, 'printers_id' => null]);
                        if ($refuse) {
                            throw new RuntimeException('Refused cartridge transfer callback');
                        }
                    };
                    $failure = null;
                    $result = null;
                    try {
                        $result = (new LegacyTransfer())->moveItems(
                            ['Printer' => [$printers[0]->id]],
                            $target,
                            ['keep_cartridgeitem' => 1, 'clean_cartridgeitem' => 2]
                        );
                    } catch (Throwable $error) {
                        $failure = $error;
                    }
                    $this->boolean($fired)->isTrue();
                    $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_cartridgeitems WHERE id=?', [$type->id]))->isIdenticalTo(1);
                    $this->variable($connection->fetchOne('SELECT name FROM glpi_cartridgeitems WHERE id=?', [$decoy->id]))->isNull();
                    if ($refuse) {
                        if ($failure !== null) {
                            $this->object($failure)->isInstanceOf(RuntimeException::class);
                            $this->string($failure->getMessage())->contains('Refused cartridge transfer callback');
                        } else {
                            $this->boolean($result)->isFalse();
                        }
                        $this->integer((int)$connection->fetchOne('SELECT cartridgeitems_id FROM glpi_cartridges WHERE id=?', [$selected->id]))->isIdenticalTo($type->id);
                        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_cartridgeitems WHERE entities_id=? AND name=?', [$target, $type->name]))->isIdenticalTo(0);
                        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_cartridgeitems_printermodels WHERE printermodels_id=?', [$model->id]))->isIdenticalTo(1);
                    } else {
                        $this->variable($failure)->isNull();
                        $this->boolean($result)->isTrue();
                        $copy = (int)$connection->fetchOne('SELECT cartridgeitems_id FROM glpi_cartridges WHERE id=?', [$selected->id]);
                        $this->integer($copy)->isGreaterThan(0)->isNotIdenticalTo($type->id)->isNotIdenticalTo($decoy->id);
                        $this->string($connection->fetchOne('SELECT name FROM glpi_cartridgeitems WHERE id=?', [$copy]))->isIdenticalTo('NULL');
                        $this->string($connection->fetchOne('SELECT comment FROM glpi_cartridgeitems WHERE id=?', [$copy]))->isIdenticalTo('null');
                        $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_cartridgeitems WHERE id=?', [$copy]))->isIdenticalTo($target);
                        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_cartridgeitems_printermodels WHERE cartridgeitems_id=? AND printermodels_id=?', [$copy, $model->id]))->isIdenticalTo(1);
                        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_cartridges WHERE cartridgeitems_id=? AND printers_id IS NULL', [$type->id]))->isIdenticalTo(1);
                    }
                }
            });
        } finally {
            $PLUGIN_HOOKS = $savedHooks;
            $plugins->setValue(null, $savedPlugins);
        }
    }


    public function testSupplierContactTransferReusesDomainNamesAndRetainsRecursiveContacts(): void
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $this->withSoftwareOwnerQueryProbe(function ($database, $connection): void {
            $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
            $manager = Orm::create($database);
            foreach ([["Contact O'Brien\\path", "First O'Brien\\path"], [null, null], ['NULL', 'null']] as $index => [$name, $firstname]) {
                $contact = new Contact();
                $contact->entities = $manager->getReference(Entity::class, $source);
                $contact->name = $name;
                $contact->firstname = $firstname;
                $manager->persist($contact);
                $destination = new Contact();
                $destination->entities = $manager->getReference(Entity::class, $target);
                $destination->name = 'Before current destination write ' . $index;
                $destination->firstname = 'Before current destination write';
                $destination->is_deleted = true;
                $manager->persist($destination);
                $decoy = new Contact();
                $decoy->entities = $manager->getReference(Entity::class, $target);
                $decoy->name = $index === 0 ? $name : null;
                $decoy->firstname = $index === 0 ? $firstname : null;
                $manager->persist($decoy);
                $suppliers = [];
                $links = [];
                foreach (['Selected supplier', 'Outside supplier'] as $supplierName) {
                    $supplier = new Supplier();
                    $supplier->entities = $manager->getReference(Entity::class, $source);
                    $supplier->name = $supplierName;
                    $manager->persist($supplier);
                    $suppliers[] = $supplier;
                    $link = new ContactSupplier();
                    $link->suppliers = $supplier;
                    $link->contacts = $contact;
                    $manager->persist($link);
                    $links[] = $link;
                }
                $manager->flush();
                $connection->update(
                    'glpi_contacts',
                    [
                        'name' => (string)$name,
                        'firstname' => (string)$firstname,
                        'comment' => 'Current destination contact',
                    ],
                    ['id' => $destination->id]
                );
                $destination->comment = 'Unflushed independent contact';
                $expected = $index === 0 ? [$destination->id, $decoy->id] : [$destination->id];
                $connection->withApplicationEntityManager(function ($outer) use ($suppliers, $links, $contact, $destination, $expected, $manager, $connection, $source, $target): void {
                    $retained = $outer->find(Contact::class, $destination->id);
                    $retained->comment = 'Unflushed outer contact';
                    $this->boolean((new LegacyTransfer())->moveItems(
                        ['Supplier' => [$suppliers[0]->id]],
                        $target,
                        ['keep_contact' => 1, 'clean_contact' => 2]
                    ))->isTrue();
                    $chosen = (int)$connection->fetchOne('SELECT contacts_id FROM glpi_contacts_suppliers WHERE id=?', [$links[0]->id]);
                    $this->boolean(in_array($chosen, $expected, true))->isTrue();
                    $this->integer((int)$connection->fetchOne('SELECT contacts_id FROM glpi_contacts_suppliers WHERE id=?', [$links[1]->id]))->isIdenticalTo($contact->id);
                    $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_contacts WHERE id=?', [$contact->id]))->isIdenticalTo($source);
                    $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_suppliers WHERE id=?', [$suppliers[0]->id]))->isIdenticalTo($target);
                    $this->boolean($manager->contains($destination))->isTrue();
                    $this->string($destination->comment)->isIdenticalTo('Unflushed independent contact');
                    $this->boolean($outer->contains($retained))->isTrue();
                    $this->string($retained->comment)->isIdenticalTo('Unflushed outer contact');
                    $this->string($connection->fetchOne('SELECT comment FROM glpi_contacts WHERE id=?', [$destination->id]))->isIdenticalTo('Current destination contact');
                });
                $manager->detach($destination);
            }
            $supplier = new Supplier();
            $supplier->entities = $manager->getReference(Entity::class, $source);
            $supplier->name = 'Exclusive and recursive contact supplier';
            $manager->persist($supplier);
            $contacts = [];
            foreach ([false, true] as $recursive) {
                $contact = new Contact();
                $contact->entities = $manager->getReference(Entity::class, $source);
                $contact->name = $recursive ? 'Retained recursive contact' : 'Exclusive transferred contact';
                $contact->is_recursive = $recursive;
                $manager->persist($contact);
                $contacts[] = $contact;
                $link = new ContactSupplier();
                $link->suppliers = $supplier;
                $link->contacts = $contact;
                $manager->persist($link);
            }
            $manager->flush();
            $this->boolean((new LegacyTransfer())->moveItems(['Supplier' => [$supplier->id]], $target, ['keep_contact' => 1]))->isTrue();
            $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_contacts WHERE id=?', [$contacts[0]->id]))->isIdenticalTo($target);
            $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_contacts WHERE id=?', [$contacts[1]->id]))->isIdenticalTo($source);
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_contacts_suppliers WHERE suppliers_id=?', [$supplier->id]))->isIdenticalTo(2);
        });
    }

    public function testSupplierContactCopyCleanupReadsHookWritesAndRollsBackRefusal(): void
    {
        global $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $savedHooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        try {
            $plugins->setValue(null, [...$savedPlugins, 'transfer_contact_fixture']);
            $this->withSoftwareOwnerQueryProbe(function ($database, $connection) use ($savedHooks): void {
                global $PLUGIN_HOOKS;
                $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
                $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
                $manager = Orm::create($database);
                foreach (['purge', 'retain', 'refuse'] as $mode) {
                    $PLUGIN_HOOKS = $savedHooks;
                    $contact = new Contact();
                    $contact->entities = $manager->getReference(Entity::class, $source);
                    $contact->name = 'Contact copy callback ' . $mode;
                    $contact->firstname = 'Actual public add';
                    $manager->persist($contact);
                    $suppliers = [];
                    $links = [];
                    foreach (['Selected', 'Outside', 'Hook witness'] as $name) {
                        $supplier = new Supplier();
                        $supplier->entities = $manager->getReference(Entity::class, $source);
                        $supplier->name = $name . ' ' . $mode;
                        $manager->persist($supplier);
                        $suppliers[] = $supplier;
                    }
                    foreach ([$suppliers[0], $suppliers[1]] as $supplier) {
                        $link = new ContactSupplier();
                        $link->suppliers = $supplier;
                        $link->contacts = $contact;
                        $manager->persist($link);
                        $links[] = $link;
                    }
                    $manager->flush();
                    $fired = false;
                    $PLUGIN_HOOKS['item_add']['transfer_contact_fixture'][LegacyContact::class] = static function (LegacyContact $item) use ($contact, $suppliers, $links, $connection, $target, $mode, &$fired): void {
                        if ($item->fields['name'] !== $contact->name || (int)$item->fields['entities_id'] !== $target) {
                            return;
                        }
                        $fired = true;
                        // After the sharing decision and public copy, before link retargeting and cleanup.
                        $connection->delete('glpi_contacts_suppliers', ['id' => $links[1]->id]);
                        if ($mode === 'retain') {
                            $connection->insert('glpi_contacts_suppliers', ['contacts_id' => $contact->id, 'suppliers_id' => $suppliers[2]->id]);
                        } elseif ($mode === 'refuse') {
                            throw new RuntimeException('Refused supplier contact copy');
                        }
                    };
                    $failure = null;
                    $result = null;
                    try {
                        $result = (new LegacyTransfer())->moveItems(
                            ['Supplier' => [$suppliers[0]->id]],
                            $target,
                            ['keep_contact' => 1, 'clean_contact' => 2]
                        );
                    } catch (Throwable $error) {
                        $failure = $error;
                    }
                    $this->boolean($fired)->isTrue();
                    $chosen = (int)$connection->fetchOne('SELECT contacts_id FROM glpi_contacts_suppliers WHERE id=?', [$links[0]->id]);
                    if ($mode === 'refuse') {
                        // The ordinary test logger may throw after the owned rollback.
                        if ($failure !== null) {
                            $this->object($failure)->isInstanceOf(RuntimeException::class);
                            $this->string($failure->getMessage())->contains('Refused supplier contact copy');
                        } else {
                            $this->boolean($result)->isFalse();
                        }
                        $this->integer($chosen)->isIdenticalTo($contact->id);
                        $this->integer((int)$connection->fetchOne('SELECT contacts_id FROM glpi_contacts_suppliers WHERE id=?', [$links[1]->id]))->isIdenticalTo($contact->id);
                        $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_suppliers WHERE id=?', [$suppliers[0]->id]))->isIdenticalTo($source);
                        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_contacts WHERE entities_id=? AND name=?', [$target, $contact->name]))->isIdenticalTo(0);
                    } else {
                        $this->variable($failure)->isNull();
                        $this->boolean($result)->isTrue();
                        $this->integer($chosen)->isGreaterThan(0)->isNotIdenticalTo($contact->id);
                        $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_contacts WHERE id=?', [$chosen]))->isIdenticalTo($target);
                        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_contacts WHERE id=?', [$contact->id]))->isIdenticalTo($mode === 'retain' ? 1 : 0);
                        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_contacts_suppliers WHERE contacts_id=? AND suppliers_id=?', [$contact->id, $suppliers[2]->id]))->isIdenticalTo($mode === 'retain' ? 1 : 0);
                    }
                }
            });
        } finally {
            $PLUGIN_HOOKS = $savedHooks;
            $plugins->setValue(null, $savedPlugins);
        }
    }

    public function testSupplierContactCopyKeepsLiteralNullText(): void
    {
        $this->login();
        global $DB;
        $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
        $selected = $this->createItem(SupplierModel::class, ['name' => $this->getUniqueString(), 'entities_id' => $source]);
        $outside = $this->createItem(SupplierModel::class, ['name' => $this->getUniqueString(), 'entities_id' => $source]);
        $contact = $this->createItem(LegacyContact::class, ['name' => 'NULL', 'firstname' => $this->getUniqueString(), 'comment' => 'null', 'entities_id' => $source]);
        foreach ([$selected, $outside] as $supplier) {
            $this->createItem(ContactSupplierModel::class, ['contacts_id' => $contact->getID(), 'suppliers_id' => $supplier->getID()]);
        }
        $transfer = new LegacyTransfer();
        $this->boolean($transfer->moveItems(['Supplier' => [$selected->getID()]], $target, ['keep_contact' => 1, 'clean_contact' => 0]))->isTrue();
        $connection = $DB->getDoctrineConnection();
        $copy = $connection->fetchAssociative('SELECT c.id, c.name, c.firstname, c.comment, c.entities_id FROM glpi_contacts c JOIN glpi_contacts_suppliers cs ON cs.contacts_id = c.id WHERE cs.suppliers_id = ?', [$selected->getID()]);
        $this->integer((int)$copy['id'])->isNotIdenticalTo((int)$contact->getID());
        $this->string($copy['name'])->isIdenticalTo('NULL');
        $this->string($copy['firstname'])->isIdenticalTo($contact->fields['firstname']);
        $this->string($copy['comment'])->isIdenticalTo('null');
        $this->integer((int)$copy['entities_id'])->isIdenticalTo($target);
        $this->integer((int)$connection->fetchOne('SELECT contacts_id FROM glpi_contacts_suppliers WHERE suppliers_id = ?', [$outside->getID()]))->isIdenticalTo((int)$contact->getID());
        $this->boolean($contact->getFromDB($contact->getID()))->isTrue();
        $this->integer((int)$contact->fields['entities_id'])->isIdenticalTo($source);
    }

    public function testDomainTransfer()
    {
        $this->login();

        //Original entity
        $fentity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        //Destination entity
        $dentity = (int)getItemByTypeName('Entity', '_test_child_2', true);

        //records types
        $type_a = (int)getItemByTypeName('DomainRecordType', 'A', true);
        $type_cname = (int)getItemByTypeName('DomainRecordType', 'CNAME', true);

        $domain = new \Domain();
        $record = new \DomainRecord();

        $did = (int)$domain->add([
           'name'         => 'glpi-project.org',
           'entities_id'  => $fentity
        ]);
        $this->integer($did)->isGreaterThan(0);
        $this->boolean($domain->getFromDB($did))->isTrue();

        $this->integer(
            (int)$record->add([
              'name'         => 'glpi-project.org.',
              'type'         => $type_a,
              'data'         => '127.0.1.1',
              'entities_id'  => $fentity,
              'domains_id'   => $did
         ])
        )->isGreaterThan(0);

        $this->integer(
            (int)$record->add([
              'name'         => 'www.glpi-project.org.',
              'type'         => $type_cname,
              'data'         => 'glpi-project.org.',
              'entities_id'  => $fentity,
              'domains_id'   => $did
         ])
        )->isGreaterThan(0);

        $this->integer(
            (int)$record->add([
              'name'         => 'doc.glpi-project.org.',
              'type'         => $type_cname,
              'data'         => 'glpi-doc.rtfd.io',
              'entities_id'  => $fentity,
              'domains_id'   => $did
         ])
        )->isGreaterThan(0);

        //transer to another entity
        $transfer = new \Transfer();

        $controller = new MockController();
        $controller->__construct = function () {
            // void
        };

        $ma = new \mock\MassiveAction([], [], 'process', $controller);

        \MassiveAction::processMassiveActionsForOneItemtype(
            $ma,
            $domain,
            [$did]
        );
        $transfer->moveItems(['Domain' => [$did]], $dentity, [$did]);
        unset($_SESSION['glpitransfer_list']);

        $this->boolean($domain->getFromDB($did))->isTrue();
        $this->integer((int)$domain->fields['entities_id'])->isidenticalTo($dentity);

        global $DB;
        $records = $DB->request([
           'FROM'   => $record->getTable(),
           'WHERE'  => [
              'domains_id' => $did
           ]
        ]);

        $this->integer(count($records))->isidenticalTo(3);
        foreach ($records as $rec) {
            $this->integer((int)$rec['entities_id'])->isidenticalTo($dentity);
        }
    }

    protected function testKeepSoftwareOptionProvider(): array
    {
        $test_entity = getItemByTypeName('Entity', '_test_root_entity', true);

        // Create test computers
        $computers_to_create = [
           'test_transfer_pc_1',
           'test_transfer_pc_2',
           'test_transfer_pc_3',
           'test_transfer_pc_4',
        ];
        foreach ($computers_to_create as $computer_name) {
            $computer = new Computer();
            $computers_id = $computer->add([
               'name'        => $computer_name,
               'entities_id' => $test_entity,
            ]);
            $this->integer($computers_id)->isGreaterThan(0);
        }

        // Create test softwares
        $softwares_to_create = [
           'test_transfer_software_1',
           'test_transfer_software_2',
           'test_transfer_software_3',
        ];
        foreach ($softwares_to_create as $software_name) {
            $software = new Software();
            $softwares_id = $software->add([
               'name'        => $software_name,
               'entities_id' => $test_entity,
            ]);
            $this->integer($softwares_id)->isGreaterThan(0);
        }

        // Create test software versions
        $software_versions_to_create = [
           'test_transfer_software_1' => ['V1', 'V2'],
           'test_transfer_software_2' => ['V1', 'V2'],
           'test_transfer_software_3' => ['V1', 'V2'],
        ];
        foreach ($software_versions_to_create as $software_name => $versions) {
            foreach ($versions as $version) {
                $softwareversion = new SoftwareVersion();
                $softwareversions_id = $softwareversion->add([
                   'name'         => $software_name . '::' . $version,
                   'softwares_id' => getItemByTypeName('Software', $software_name, true),
                   'entities_id'  => $test_entity,
                ]);
                $this->integer($softwareversions_id)->isGreaterThan(0);
            }
        }

        // Link softwares and computers
        $item_softwareversion_ids = [];
        $item_softwareversion_to_create = [
           'test_transfer_pc_1' => ['test_transfer_software_1::V1', 'test_transfer_software_2::V1'],
           'test_transfer_pc_2' => ['test_transfer_software_1::V2', 'test_transfer_software_2::V2'],
           'test_transfer_pc_3' => ['test_transfer_software_2::V1', 'test_transfer_software_3::V2'],
           'test_transfer_pc_4' => ['test_transfer_software_1::V2', 'test_transfer_software_3::V1'],
        ];
        foreach ($item_softwareversion_to_create as $computer_name => $versions) {
            foreach ($versions as $version) {
                $item_softwareversion = new Item_SoftwareVersion();
                $item_softwareversions_id = $item_softwareversion->add([
                   'items_id'     => getItemByTypeName('Computer', $computer_name, true),
                   'itemtype'     => 'Computer',
                   'softwareversions_id' => getItemByTypeName('SoftwareVersion', $version, true),
                   'entities_id'  => $test_entity,
                ]);
                $this->integer($item_softwareversions_id)->isGreaterThan(0);
                $item_softwareversion_ids[] = $item_softwareversions_id;
            }
        }

        return [
           [
              'items' => [
                 'Computer' => [
                    getItemByTypeName('Computer', 'test_transfer_pc_1', true),
                    getItemByTypeName('Computer', 'test_transfer_pc_2', true),
                 ]
              ],
              'entities_id_destination' => $test_entity,
              'transfer_options'        => ['keep_software' => 1],
              'expected_softwares_after_transfer' => [
                 'Computer' => [
                    getItemByTypeName('Computer', 'test_transfer_pc_1', true) => [
                       $item_softwareversion_ids[0],
                       $item_softwareversion_ids[1]
                    ],
                    getItemByTypeName('Computer', 'test_transfer_pc_2', true) => [
                       $item_softwareversion_ids[2],
                       $item_softwareversion_ids[3]
                    ],
                 ]
              ]
           ],
           [
              'items' => [
                 'Computer' => [
                    getItemByTypeName('Computer', 'test_transfer_pc_3', true),
                    getItemByTypeName('Computer', 'test_transfer_pc_4', true),
                 ]
              ],
              'entities_id_destination' => $test_entity,
              'transfer_options'        => ['keep_software' => 0],
              'expected_softwares_after_transfer' => [
                 'Computer' => [
                    getItemByTypeName('Computer', 'test_transfer_pc_3', true) => [],
                    getItemByTypeName('Computer', 'test_transfer_pc_4', true) => [],
                 ]
              ]
           ]
        ];
    }

    /**
     * @dataProvider testKeepSoftwareOptionProvider
     */
    public function testKeepSoftwareOption(
        array $items,
        int $entities_id_destination,
        array $transfer_options,
        array $expected_softwares_after_transfer
    ): void {
        $tranfer = new \Transfer();
        $tranfer->moveItems($items, $entities_id_destination, $transfer_options);

        foreach ($items as $itemtype => $ids) {
            foreach ($ids as $id) {
                $item_softwareversion = new Item_SoftwareVersion();
                $data = $item_softwareversion->find([
                   'items_id' => $id,
                   'itemtype' => $itemtype
                ]);
                $found_ids = array_column($data, 'id');
                $this->array($found_ids)->isEqualTo($expected_softwares_after_transfer[$itemtype][$id]);
            }
        }
    }

    /** A separate real session keeps DbTestCase's caller frame completely untouched. */
    private function withSoftwareOwnerQueryProbe(callable $operation): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        $originalLevel = $original->getDoctrineConnection()->getTransactionNestingLevel();
        $logger = new class () extends AbstractLogger {
            public array $queries = [];
            public ?Closure $beforeCurrentInstallations = null;

            public function log($level, $message, array $context = []): void
            {
                if (!isset($context['sql'])) {
                    return; // Never retain connection credentials or bound application data.
                }
                $sql = str_replace(['`', '"'], '', $context['sql']);
                $this->queries[] = $sql;
                if ($this->beforeCurrentInstallations !== null
                    && preg_match('/\bFROM\s+glpi_items_softwareversions\b/i', $sql)
                    && preg_match('/FOR (?:SHARE|UPDATE)|LOCK IN SHARE MODE/i', $sql)) {
                    $change = $this->beforeCurrentInstallations;
                    $this->beforeCurrentInstallations = null;
                    $change();
                }
            }

            public function ownerReads(): int
            {
                return count(array_filter($this->queries, static fn (string $sql): bool => (bool)preg_match('/\bFROM\s+glpi_softwareversions\b/i', $sql)));
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($logger)]);
        $parameters = $original->getDoctrineConnection()->getParams();
        $connection = $original->getProvider() === 'pgsql'
            ? PostgresConnection::create($parameters, $configuration)
            : MySQLConnection::create($parameters, $configuration);
        // Test-only adapter admission: a real canonical connection, never the
        // original adapter's physical owner or a mock transaction implementation.
        $probe = clone $original;
        (new ReflectionProperty(DBAdapter::class, 'doctrine'))->setValue($probe, $connection);
        $frame = null;
        $primary = null;
        try {
            $DB = $probe;
            $frame = OwnedMutationFrame::begin($connection);
            $operation($probe, $connection, $logger);
        } catch (Throwable $error) {
            $primary = $error;
        } finally {
            $DB = $original;
            $_SESSION = $session;
            try {
                if ($frame !== null) {
                    $frame->rollBack();
                }
            } catch (Throwable $cleanup) {
                $primary = $primary === null ? $cleanup : new MutationRollbackFailure($primary, $cleanup);
            }
            try {
                $probe->close();
            } catch (Throwable $cleanup) {
                $primary = $primary === null ? $cleanup : new MutationCleanupFailure($primary, $cleanup);
            }
        }
        if ($primary !== null) {
            throw $primary;
        }
        $this->integer($original->getDoctrineConnection()->getTransactionNestingLevel())->isIdenticalTo($originalLevel);
    }

    public function testTransferStopsAfterCallbackReplacesFrameOrWriter(): void
    {
        global $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $savedHooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        try {
            $plugins->setValue(null, [...$savedPlugins, 'transfer_frame_fixture']);
            $this->withSoftwareOwnerQueryProbe(function ($database, $connection, $logger) use ($savedHooks): void {
                global $DB, $PLUGIN_HOOKS;
                $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
                $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
                $manager = Orm::create($database);
                $contacts = [];
                foreach (['First callback owner', 'Later transfer owner'] as $name) {
                    $contact = new Contact();
                    $contact->entities = $manager->getReference(Entity::class, $source);
                    $contact->name = $name;
                    $manager->persist($contact);
                    $contacts[] = $contact;
                }
                $manager->flush();
                $ids = array_map(static fn ($contact): int => $contact->id, $contacts);
                $level = $connection->getTransactionNestingLevel();
                foreach (['item_update', 'item_transfer', 'refused_update', 'writer_swap', 'owned_failure'] as $mode) {
                    $PLUGIN_HOOKS = $savedHooks;
                    $replacement = null;
                    $retained = null;
                    $fired = false;
                    $session = $_SESSION;
                    $change = function () use ($connection, $database, $logger, $ids, $mode, &$replacement, &$fired): void {
                        $fired = true;
                        if ($mode === 'writer_swap') {
                            // Adapter identity matters even with the same physical owner.
                            $GLOBALS['DB'] = clone $database;
                        } elseif ($mode !== 'owned_failure') {
                            $connection->rollBack();
                            $replacement = OwnedMutationFrame::begin($connection);
                            $connection->update('glpi_contacts', ['comment' => 'Replacement witness'], ['id' => $ids[0]]);
                        }
                        $_SESSION['transfer_frame_fixture'] = $mode;
                        $logger->queries = [];
                        if ($mode === 'owned_failure') {
                            throw new RuntimeException('Refused owned transfer callback');
                        }
                    };
                    $PLUGIN_HOOKS['item_update']['transfer_frame_fixture'][LegacyContact::class] = static function (LegacyContact $item) use ($ids, $mode, $change, &$retained): void {
                        if ((int)$item->getID() === $ids[0]) {
                            $retained = $item;
                            if (in_array($mode, ['item_update', 'writer_swap', 'owned_failure'], true)) {
                                $change();
                            }
                        }
                    };
                    $PLUGIN_HOOKS['pre_item_update']['transfer_frame_fixture'][LegacyContact::class] = static function (LegacyContact $item) use ($ids, $mode, $change, &$retained): void {
                        if ($mode === 'refused_update' && (int)$item->getID() === $ids[0]) {
                            $retained = $item;
                            $item->input = [];
                            $change();
                        }
                    };
                    $PLUGIN_HOOKS['item_transfer']['transfer_frame_fixture'] = static function (array $item) use ($ids, $mode, $change): void {
                        if ($mode === 'item_transfer' && $item['type'] === 'Contact' && (int)$item['id'] === $ids[0]) {
                            $change();
                        }
                    };
                    $failure = null;
                    $result = null;
                    try {
                        try {
                            $result = (new LegacyTransfer())->moveItems(['Contact' => $ids], $target, []);
                        } catch (Throwable $error) {
                            $failure = $error;
                        } finally {
                            $DB = $database;
                        }
                        $this->boolean($fired)->isTrue();
                        // No later model, binding, audit or queue write may follow
                        // the callback that invalidated the active operation.
                        $writes = array_values(array_filter($logger->queries, static fn (string $sql): bool =>
                            preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/i', $sql) === 1));
                        $this->array($writes)->isEmpty();
                        if ($replacement !== null) {
                            $this->object($failure)->isInstanceOf(MutationRollbackFailure::class);
                            $this->object($failure->primary)->isInstanceOf(TransactionOwnershipMismatch::class);
                            $replacement->assertActive();
                            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level + 1);
                            $this->string($_SESSION['transfer_frame_fixture'])->isIdenticalTo($mode);
                            $this->integer((int)$retained->fields['entities_id'])->isIdenticalTo($mode === 'refused_update' ? $source : $target);
                            $this->string($connection->fetchOne('SELECT comment FROM glpi_contacts WHERE id=?', [$ids[0]]))->isIdenticalTo('Replacement witness');
                        } else {
                            // The strict test logger throws when runTransfer logs
                            // an owned refusal, after its proven rollback/rewind.
                            $this->object($failure)->isInstanceOf(RuntimeException::class);
                            $this->string($failure->getMessage())->contains($mode === 'writer_swap'
                                ? 'A transfer callback replaced its active writer.'
                                : 'Refused owned transfer callback');
                            $this->variable($result)->isNull();
                            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
                            $this->boolean(isset($_SESSION['transfer_frame_fixture']))->isFalse();
                            $this->integer((int)$retained->fields['entities_id'])->isIdenticalTo($source);
                        }
                        $owners = array_map('intval', $connection->fetchFirstColumn('SELECT entities_id FROM glpi_contacts WHERE id IN (?, ?) ORDER BY id', $ids));
                        $this->array($owners)->isIdenticalTo([$source, $source]);
                    } finally {
                        $DB = $database;
                        $PLUGIN_HOOKS = $savedHooks;
                        if ($replacement !== null) {
                            $replacement->rollBack();
                        }
                        $_SESSION = $session;
                    }
                }
                // A normal retry still joins and preserves the caller's frame.
                $this->boolean((new LegacyTransfer())->moveItems(['Contact' => $ids], $target, []))->isTrue();
                $this->array(array_map('intval', $connection->fetchFirstColumn('SELECT entities_id FROM glpi_contacts WHERE id IN (?, ?) ORDER BY id', $ids)))->isIdenticalTo([$target, $target]);
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
            });
        } finally {
            $PLUGIN_HOOKS = $savedHooks;
            $plugins->setValue(null, $savedPlugins);
        }
    }

    public function testOverriddenHistoryCannotContinueIntoTicketMutations(): void
    {
        $this->withSoftwareOwnerQueryProbe(function ($database, $connection, $logger): void {
            // Login updates the actor referenced by the ticket's last updater;
            // keep that write on the same connection as the transfer fixture.
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $target = (int)getItemByTypeName('Entity', '_test_child_2', true);
            $manager = Orm::create($database);
            $entity = $manager->getReference(Entity::class, $source);
            $computer = new ComputerEntity();
            $computer->entities = $entity;
            $computer->name = 'Public related transfer boundary';
            $ticket = new Ticket();
            $ticket->entities = $entity;
            $ticket->name = 'Ticket must retain its original asset';
            $manager->persist($computer);
            $manager->persist($ticket);
            $manager->flush();
            $link = new ItemTicket();
            $link->itemtype = 'Computer';
            $link->computer = $computer;
            $link->tickets = $ticket;
            $history = new Log();
            $history->itemtype = 'Computer';
            $history->items_id = $computer->id;
            $history->new_value = 'Retained original history';
            $manager->persist($link);
            $manager->persist($history);
            $manager->flush();
            $transfer = new class () extends LegacyTransfer {
                public ?Closure $afterHistory = null;
                public int $ticketCalls = 0;

                public function transferHistory($itemtype, $ID, $newID)
                {
                    parent::transferHistory($itemtype, $ID, $newID);
                    $callback = $this->afterHistory;
                    $this->afterHistory = null;
                    if ($callback !== null) {
                        $callback();
                    }
                }

                public function transferTickets($itemtype, $ID, $newID)
                {
                    ++$this->ticketCalls;
                    parent::transferTickets($itemtype, $ID, $newID);
                }
            };
            $replacement = null;
            $level = $connection->getTransactionNestingLevel();
            $transfer->afterHistory = static function () use ($connection, $computer, $logger, &$replacement): void {
                $connection->rollBack();
                $replacement = OwnedMutationFrame::begin($connection);
                $connection->update('glpi_computers', ['comment' => 'Public helper replacement witness'], ['id' => $computer->id]);
                $logger->queries = [];
            };
            try {
                $this->exception(static fn () => $transfer->moveItems(['Computer' => [$computer->id]], $target, ['keep_ticket' => 1]))
                    ->isInstanceOf(MutationRollbackFailure::class);
                $this->object($replacement)->isInstanceOf(OwnedMutationFrame::class);
                $replacement->assertActive();
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level + 1);
                $this->integer($transfer->ticketCalls)->isIdenticalTo(0);
                $this->array(array_values(array_filter($logger->queries, static fn (string $sql): bool =>
                    preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/i', $sql) === 1)))->isEmpty();
                $this->string($connection->fetchOne('SELECT comment FROM glpi_computers WHERE id=?', [$computer->id]))->isIdenticalTo('Public helper replacement witness');
                $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_computers WHERE id=?', [$computer->id]))->isIdenticalTo($source);
                $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_items_tickets WHERE id=? AND computers_id=? AND tickets_id=?', [$link->id, $computer->id, $ticket->id]))->isIdenticalTo(1);
                $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_logs WHERE id=?', [$history->id]))->isIdenticalTo(1);
            } finally {
                $replacement?->rollBack();
            }
            // The actual public overrides remain usable with normal ownership.
            $this->boolean($transfer->moveItems(['Computer' => [$computer->id]], $target, ['keep_ticket' => 1]))->isTrue();
            $this->integer($transfer->ticketCalls)->isIdenticalTo(1);
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_items_tickets WHERE id=?', [$link->id]))->isIdenticalTo(0);
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_logs WHERE id=?', [$history->id]))->isIdenticalTo(0);
            $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_computers WHERE id=?', [$computer->id]))->isIdenticalTo($target);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        });
    }

    public function testTransferInstallationSnapshotsPreserveUnflushedAggregateState(): void
    {
        $this->login();
        $this->withSoftwareOwnerQueryProbe(function ($database, $connection, $logger): void {
            $fixture = Orm::create($database);
            $root = $fixture->getReference(Entity::class, (int)getItemByTypeName('Entity', '_test_root_entity', true));
            $software = new SoftwareEntity();
            $software->entities = $root;
            $software->name = 'Persisted transfer software';
            $fixture->persist($software);
            $unrelated = new SoftwareEntity();
            $unrelated->entities = $root;
            $unrelated->name = 'Persisted unrelated software';
            $fixture->persist($unrelated);
            $computer = new ComputerEntity();
            $computer->entities = $root;
            $fixture->persist($computer);
            $version = new SoftwareVersionEntity();
            $version->entities = $root;
            $version->softwares = $software;
            $version->name = 'Persisted transfer version';
            $fixture->persist($version);
            $installation = new ItemSoftwareVersion();
            $installation->itemtype = 'Computer';
            $installation->entities = $root;
            $installation->computer = $computer;
            $installation->softwareversions = $version;
            $fixture->persist($installation);
            $fixture->flush();
            $before = $connection->fetchAllAssociative('SELECT id, softwareversions_id, computers_id FROM glpi_items_softwareversions WHERE computers_id=? ORDER BY id', [$computer->id]);

            $service = new SoftwareAssignmentService($database);
            // Pending unrelated software survives the selected owner's intentional refresh.
            $owner = (new ReflectionProperty(SoftwareAssignmentService::class, 'manager'))->getValue($service);
            $ownedSoftware = $owner->find(SoftwareEntity::class, $unrelated->id);
            $ownedVersion = $owner->find(SoftwareVersionEntity::class, $version->id);
            $ownedSoftware->name = 'Pending aggregate software';
            $ownedVersion->name = 'Pending aggregate version';
            $software->name = 'Pending independent fixture edit';
            for ($iteration = 0; $iteration < 2; ++$iteration) {
                $service->lockTransferSubject('Computer', $computer->id);
                $this->boolean($owner->contains($ownedSoftware))->isTrue();
                $this->boolean($owner->contains($ownedVersion))->isTrue();
                $this->string($ownedSoftware->name)->isIdenticalTo('Pending aggregate software');
                $this->string($ownedVersion->name)->isIdenticalTo('Pending aggregate version');
                $this->boolean($fixture->contains($software))->isTrue();
                $this->string($software->name)->isIdenticalTo('Pending independent fixture edit');
                $this->string($connection->fetchOne('SELECT name FROM glpi_softwares WHERE id=?', [$unrelated->id]))->isIdenticalTo('Persisted unrelated software');
                $this->string($connection->fetchOne('SELECT name FROM glpi_softwareversions WHERE id=?', [$version->id]))->isIdenticalTo('Persisted transfer version');
                $this->array($connection->fetchAllAssociative('SELECT id, softwareversions_id, computers_id FROM glpi_items_softwareversions WHERE computers_id=? ORDER BY id', [$computer->id]))->isIdenticalTo($before);
            }
        });
    }

    public function testManyInstalledVersionsUseBoundedOwnerReads(): void
    {
        $this->login();
        $this->withSoftwareOwnerQueryProbe(function ($database, $connection, $logger): void {
            $manager = Orm::create($database);
            $root = $manager->getReference(Entity::class, (int)getItemByTypeName('Entity', '_test_root_entity', true));
            $software = new SoftwareEntity();
            $software->entities = $root;
            $software->name = 'Bounded installation owners';
            $manager->persist($software);
            $computer = new ComputerEntity();
            $computer->entities = $root;
            $computer->name = 'Bounded installation subject';
            $manager->persist($computer);
            $manager->flush();
            $counts = [];
            for ($index = 1; $index <= 25; ++$index) {
                $version = new SoftwareVersionEntity();
                $version->entities = $root;
                $version->softwares = $software;
                $version->name = 'Owner version ' . $index;
                $manager->persist($version);
                $installation = new ItemSoftwareVersion();
                $installation->itemtype = 'Computer';
                $installation->entities = $root;
                $installation->computer = $computer;
                $installation->softwareversions = $version;
                $manager->persist($installation);
                $manager->flush();
                if (in_array($index, [1, 25], true)) {
                    $before = $connection->fetchAllAssociative('SELECT id, softwareversions_id, computers_id FROM glpi_items_softwareversions WHERE computers_id=? ORDER BY id', [$computer->id]);
                    $logger->queries = [];
                    (new SoftwareAssignmentService($database))->lockTransferSubject('Computer', $computer->id);
                    $counts[] = $logger->ownerReads();
                    $this->integer($logger->ownerReads())->isIdenticalTo(2);
                    $model = new Computer();
                    $this->boolean($model->can($computer->id, UPDATE))->isTrue();
                    $logger->queries = [];
                    $this->boolean($model->update(['id' => $computer->id, 'is_template' => $index === 1 ? 1 : 0]))->isTrue();
                    $this->integer($logger->ownerReads())->isIdenticalTo(2);
                    $this->array($connection->fetchAllAssociative('SELECT id, softwareversions_id, computers_id FROM glpi_items_softwareversions WHERE computers_id=? ORDER BY id', [$computer->id]))->isIdenticalTo($before);
                }
            }
            $this->array($counts)->isIdenticalTo([2, 2]);
        });
    }

    public function testInstalledVersionOwnerRecheckedAfterGraphLock(): void
    {
        $this->login();
        $this->withSoftwareOwnerQueryProbe(function ($database, $connection, $logger): void {
            $manager = Orm::create($database);
            $root = $manager->getReference(Entity::class, (int)getItemByTypeName('Entity', '_test_root_entity', true));
            $owners = [];
            foreach (['Initially selected owner', 'Changed owner'] as $name) {
                $owner = new SoftwareEntity();
                $owner->entities = $root;
                $owner->name = $name;
                $manager->persist($owner);
                $owners[] = $owner;
            }
            $computer = new ComputerEntity();
            $computer->entities = $root;
            $manager->persist($computer);
            $version = new SoftwareVersionEntity();
            $version->entities = $root;
            $version->softwares = $owners[0];
            $manager->persist($version);
            $installation = new ItemSoftwareVersion();
            $installation->itemtype = 'Computer';
            $installation->entities = $root;
            $installation->computer = $computer;
            $installation->softwareversions = $version;
            $manager->persist($installation);
            $manager->flush();
            $changeFrame = OwnedMutationFrame::begin($connection);
            try {
                // Deterministic actual writer interleaving before the second
                // observation. This is not claimed as a two-session race test.
                $logger->beforeCurrentInstallations = static function () use ($connection, $version, $owners): void {
                    $connection->update('glpi_softwareversions', ['softwares_id' => $owners[1]->id], ['id' => $version->id]);
                };
                $this->exception(static fn () => (new SoftwareAssignmentService($database))->lockTransferSubject('Computer', $computer->id))
                    ->isInstanceOf(SoftwareAssignmentCancelled::class)
                    ->hasMessage('Transfer installation membership changed before locking; retry the command.');
                $this->variable($logger->beforeCurrentInstallations)->isNull();
                $this->integer((int)$connection->fetchOne('SELECT softwares_id FROM glpi_softwareversions WHERE id=?', [$version->id]))->isIdenticalTo($owners[1]->id);
            } finally {
                $changeFrame->rollBack();
            }
            $this->integer((int)$connection->fetchOne('SELECT softwares_id FROM glpi_softwareversions WHERE id=?', [$version->id]))->isIdenticalTo($owners[0]->id);
        });
    }

}
