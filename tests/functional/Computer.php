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
use CommonDBTM;
use Computer as ComputerModel;
use Computer_Item;
use DBAdapter;
use DBmysql;
use DBpgsql;
use DbTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Query;
use Dropdown;
use FieldUnicity;
use Group;
use Location;
use State;
use Toolbox;
use User;
use itsmng\Database\Entity\Computer as ComputerEntity;
use itsmng\Database\Entity\FieldUnicity as UnicityRecord;
use itsmng\Database\MappedStorage;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\MySQLConnection;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\PostgresConnection;
use itsmng\Database\Repository\AssetRepository;
use itsmng\Database\Repository\RecordWriter;
use itsmng\Database\TransactionOwnershipMismatch;
use Log;
use Notepad as NotepadModel;
use Session;
use itsmng\Database\Entity\Notepad as NoteRecord;
use PluginNotepadOwnershipParent;
use Item_SoftwareLicense as AllocationModel;
use Item_SoftwareVersion as InstallationModel;
use Software as SoftwareModel;
use SoftwareLicense as LicenseModel;
use SoftwareVersion as VersionModel;
use RuntimeException;
use Doctrine\DBAL\Exception as NoteDatabaseException;
use Monitor;
use Peripheral;
use Phone;
use Plugin;
use Printer;
use QueuedNotification;
use ReflectionProperty;
use Throwable;
use TypeError;
use stdClass;
use tests\fixtures\ScalarReadProbe;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\ComputerItemReadOperation;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Entity\ComputerItem as ComputerItemRecord;
use itsmng\Database\Repository\RecordRepository;
use LogicException;
use mock\DBmysql as ComputerItemAdapterProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';
require_once dirname(__DIR__) . '/fixtures/notepadownership.php';

/* Test for inc/computer.class.php */

class Computer extends DbTestCase
{
    public function testUniquenessPreparationRetainsCustomRouteAndConversionOrder(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $connection = $DB->getDoctrineConnection();
            $kindName = $this->getUniqueString();
            $id = (new RecordWriter(Orm::create($DB)))->insert('glpi_fieldunicities', [
                'name' => $this->getUniqueString(), 'itemtype' => $kindName, 'entities_id' => 0,
                'fields' => 'serial', 'is_active' => 1,
            ]);
            $observer = new class () {
                public array $trace = [];
                public int $clears = 0;
                public int $clearsAtConversion = -1;

                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $events = new EventManager();
            $events->addEventListener(['onClear'], $observer);
            $selected = new class ($connection, $events, $observer) extends ScalarReadProbe {
                public function __construct($connection, private EventManager $events, private object $observer)
                {
                    parent::__construct($connection);
                }

                public function getEventManager(): EventManager
                {
                    $this->observer->trace[] = 'constructed';
                    return $this->events;
                }

                public function isTransactionActive(): bool
                {
                    return $this->selected->isTransactionActive();
                }
            };
            $other = new ScalarReadProbe($connection);
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new ComputerItemAdapterProbe();
            $alternate = new ComputerItemAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = $selected;
            $this->calling($alternate)->getDoctrineConnection = $other;
            $this->calling($adapter)->getProvider = $original->getProvider();
            $this->calling($alternate)->getProvider = $original->getProvider();
            $DB = $adapter;
            $kind = new class ($kindName, $observer, $alternate) {
                public function __construct(private string $name, private object $observer, private DBAdapter $alternate)
                {
                }

                public function __toString(): string
                {
                    global $DB;
                    $this->observer->trace[] = 'converted';
                    $this->observer->clearsAtConversion = $this->observer->clears;
                    $DB = $this->alternate;
                    return $this->name;
                }
            };
            $rows = FieldUnicity::getUnicityFieldsConfig($kind, 0);
            $this->array(array_map('intval', array_column($rows, 'id')))->isIdenticalTo([$id]);
            $this->string($observer->trace[0])->isIdenticalTo('constructed');
            $this->string($observer->trace[count($observer->trace) - 1])->isIdenticalTo('converted');
            // Ancestor readers may clear their own managers before item-type conversion.
            $this->integer($observer->clears)->isIdenticalTo($observer->clearsAtConversion);
            $this->array($other->queries)->isEmpty();
            $DB = $adapter;
            $before = count(array_filter($selected->queries, static fn (array $query): bool =>
                str_contains($query['sql'], 'glpi_fieldunicities')));
            $this->exception(static fn () => FieldUnicity::getUnicityFieldsConfig(new stdClass(), 0))
                ->isInstanceOf(TypeError::class);
            $after = count(array_filter($selected->queries, static fn (array $query): bool =>
                str_contains($query['sql'], 'glpi_fieldunicities')));
            $this->integer($after)->isIdenticalTo($before);
            $this->array(array_map('intval', array_column(FieldUnicity::getUnicityFieldsConfig($kindName, 0), 'id')))
                ->isIdenticalTo([$id]);
        } finally {
            $DB = $original;
            $_SESSION = $session;
        }
    }

    public function testUniquenessConfigurationStaysFreshBeforeActualAddAndUpdate(): void
    {
        global $DB, $CFG_GLPI;
        $session = $_SESSION;
        $types = $CFG_GLPI['unicity_types'];
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $connection = $DB->getDoctrineConnection();
            // The enclosing ordinary fixture transaction restores all previous rules.
            $connection->delete('glpi_fieldunicities', ['itemtype' => ComputerModel::class]);
            $CFG_GLPI['unicity_types'][] = ComputerModel::class;
            $serial = $this->getUniqueString();
            $first = $this->createItem(ComputerModel::class, [
                'name' => $this->getUniqueString(), 'entities_id' => $entity, 'serial' => $serial,
            ]);
            $second = $this->createItem(ComputerModel::class, [
                'name' => $this->getUniqueString(), 'entities_id' => $entity, 'serial' => $this->getUniqueString(),
            ]);
            $makeRule = function (int $scope, string $field, bool $active, bool $recursive): FieldUnicity {
                $rule = new FieldUnicity();
                $id = $rule->add([
                    'name' => $this->getUniqueString(),
                    'itemtype' => ComputerModel::class,
                    'entities_id' => $scope,
                    '_fields' => [$field],
                    'is_active' => (int)$active,
                    'is_recursive' => (int)$recursive,
                    'action_refuse' => 1,
                ]);
                $this->integer($id)->isGreaterThan(0);
                $this->boolean($rule->getFromDB($id))->isTrue();
                $this->string($rule->getField('fields'))->isIdenticalTo($field);
                return $rule;
            };
            $global = $makeRule(0, 'serial', true, false);
            $connection->update('glpi_fieldunicities', ['entities_id' => null], ['id' => $global->getID()]);
            $root = $makeRule(0, 'serial', true, true);
            $local = $makeRule($entity, 'serial', true, false);
            $tie = $makeRule($entity, 'otherserial', false, false);
            $ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'id'));
            $this->array($ids(FieldUnicity::getUnicityFieldsConfig(ComputerModel::class, $entity)))
                ->isIdenticalTo([(int)$local->getID()]);
            $this->array($ids(FieldUnicity::getUnicityFieldsConfig(ComputerModel::class, $entity, false)))
                ->isIdenticalTo([(int)$local->getID(), (int)$tie->getID()]);
            $this->array($ids(FieldUnicity::getUnicityFieldsConfig(ComputerModel::class, 0)))
                ->isIdenticalTo([(int)$root->getID()]);
            $connection->update('glpi_fieldunicities', ['is_active' => true], ['id' => $tie->getID()], ['is_active' => Types::BOOLEAN]);
            $this->array($ids(FieldUnicity::getUnicityFieldsConfig(ComputerModel::class, $entity)))
                ->isIdenticalTo([(int)$local->getID(), (int)$tie->getID()]);
            $duplicate = new ComputerModel();
            $this->boolean($duplicate->add([
                'name' => $this->getUniqueString(), 'entities_id' => $entity, 'serial' => $serial,
            ], ['unicity_error_message' => false, 'add_event_on_duplicate' => false]))->isFalse();
            $this->boolean($second->update(
                ['id' => $second->getID(), 'serial' => $serial],
                1,
                ['unicity_error_message' => false, 'add_event_on_duplicate' => false]
            ))->isFalse();
            $independent = Orm::create($DB);
            $dirty = $independent->find(UnicityRecord::class, (int)$local->getID());
            $dirty->is_active = false;
            $rows = FieldUnicity::getUnicityFieldsConfig(ComputerModel::class, $entity);
            $this->array($ids($rows))->isIdenticalTo([(int)$local->getID(), (int)$tie->getID()]);
            $this->integer($rows[0]['is_active'])->isIdenticalTo(1);
            $this->boolean($independent->contains($dirty))->isTrue();
            $this->boolean($dirty->is_active)->isFalse();
            Orm::read($DB, function (EntityManager $owner) use ($entity, $local, $tie, $ids): void {
                $dirty = $owner->find(UnicityRecord::class, (int)$local->getID());
                $dirty->fields = 'Unflushed outer fields';
                $rows = FieldUnicity::getUnicityFieldsConfig(ComputerModel::class, $entity);
                $this->array($ids($rows))->isIdenticalTo([(int)$local->getID(), (int)$tie->getID()]);
                $this->string($rows[0]['fields'])->isIdenticalTo('serial');
                $this->boolean($owner->contains($dirty))->isTrue();
                $this->string($dirty->fields)->isIdenticalTo('Unflushed outer fields');
            });
            $connection->delete('glpi_fieldunicities', ['id' => $local->getID()]);
            $connection->delete('glpi_fieldunicities', ['id' => $tie->getID()]);
            $this->array($ids(FieldUnicity::getUnicityFieldsConfig(ComputerModel::class, $entity)))
                ->isIdenticalTo([(int)$root->getID()]);
            $connection->delete('glpi_fieldunicities', ['id' => $root->getID()]);
            $rows = FieldUnicity::getUnicityFieldsConfig(ComputerModel::class, $entity);
            $this->array($ids($rows))->isIdenticalTo([(int)$global->getID()]);
            $this->variable($rows[0]['entities_id'])->isNull();
            $connection->delete('glpi_fieldunicities', ['id' => $global->getID()]);
            $this->array(FieldUnicity::getUnicityFieldsConfig(ComputerModel::class, $entity))->isEmpty();
            $independent->clear();
        } finally {
            $CFG_GLPI['unicity_types'] = $types;
            $_SESSION = $session;
        }
    }

    public function testComputerItemNestedCommandsKeepOuterIdentityAndDirtyOwners(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)$_SESSION['glpiactive_entity'];
            $computer = $this->createItem(ComputerModel::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
            $other = $this->createItem(ComputerModel::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
            $monitor = $this->createItem(Monitor::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_global' => true]);
            $innerMonitor = $this->createItem(Monitor::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_global' => true]);
            $link = new Computer_Item();
            $innerId = null;
            $inside = false;
            $events = [];
            $plugins->setValue(null, [...$active, 'computer_item_nested_fixture']);
            $PLUGIN_HOOKS['post_prepareadd']['computer_item_nested_fixture'][Computer_Item::class] = static function (
                Computer_Item $model
            ) use ($link, $other, $innerMonitor, &$innerId, &$inside): void {
                if ($model === $link && !$inside) {
                    $inside = true;
                    try {
                        $innerId = $model->add([
                            'computers_id' => $other->getID(), 'itemtype' => 'Monitor', 'items_id' => $innerMonitor->getID(),
                        ]);
                    } finally {
                        $inside = false;
                    }
                }
            };
            $PLUGIN_HOOKS['item_add']['computer_item_nested_fixture'][Computer_Item::class] = static function (
                Computer_Item $model
            ) use ($link, &$events): void {
                if ($model === $link) {
                    $events[] = [(int)$model->getID(), (int)$model->fields['computers_id'], (int)$model->fields['items_id']];
                }
            };
            $independent = Orm::create($DB);
            $dirty = $independent->find(ComputerEntity::class, (int)$computer->getID());
            $dirty->name = 'Independent pending name';
            Orm::read($DB, function (EntityManager $owner) use ($link, $computer, $monitor, $other, $innerMonitor, &$innerId, &$events): void {
                $outer = $owner->find(ComputerEntity::class, (int)$other->getID());
                $outer->name = 'Enclosing pending name';
                $id = $link->add([
                    'computers_id' => $computer->getID(), 'itemtype' => 'Monitor', 'items_id' => $monitor->getID(),
                ]);
                $this->integer((int)$id)->isGreaterThan(0);
                $this->integer((int)$innerId)->isGreaterThan(0);
                $this->integer((int)$link->getID())->isIdenticalTo((int)$id);
                $this->array($events)->isIdenticalTo([
                    [(int)$innerId, (int)$other->getID(), (int)$innerMonitor->getID()],
                    [(int)$id, (int)$computer->getID(), (int)$monitor->getID()],
                ]);
                $this->boolean($owner->contains($outer))->isTrue();
                $this->string($outer->name)->isIdenticalTo('Enclosing pending name');
            });
            $this->boolean($independent->contains($dirty))->isTrue();
            $this->string($dirty->name)->isIdenticalTo('Independent pending name');
            $connection = $DB->getDoctrineConnection();
            foreach ($events as [$id, $computerId, $itemId]) {
                $row = $connection->fetchAssociative('SELECT * FROM glpi_computers_items WHERE id=?', [$id]);
                $this->array($row)->isNotEmpty();
                $this->integer((int)$row['computers_id'])->isIdenticalTo($computerId);
                $this->integer((int)$row['monitors_id'])->isIdenticalTo($itemId);
                $this->variable($row['opaque_parent_id'])->isNull();
            }
            $this->boolean($computer->getFromDB($computer->getID()))->isTrue();
            $this->boolean($other->getFromDB($other->getID()))->isTrue();
            $this->boolean($computer->fields['name'] === 'Independent pending name')->isFalse();
            $this->boolean($other->fields['name'] === 'Enclosing pending name')->isFalse();
        } finally {
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
            $_SESSION = $session;
        }
    }

    public function testComputerItemRejectsStandalonePreparedWriterSwitchBeforeFiltering(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $original = $DB;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)$_SESSION['glpiactive_entity'];
            $computer = $this->createItem(ComputerModel::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
            $monitor = $this->createItem(Monitor::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_global' => true]);
            $connection = $DB->getDoctrineConnection();
            $this->mockGenerator()->orphanize('__construct');
            $alternate = new ComputerItemAdapterProbe();
            $this->calling($alternate)->getDoctrineConnection = $connection;
            $prepared = 0;
            $plugins->setValue(null, [...$active, 'computer_item_writer_fixture']);
            $PLUGIN_HOOKS['post_prepareadd']['computer_item_writer_fixture'][Computer_Item::class] = static function (
                Computer_Item $model
            ) use ($alternate, &$prepared): void {
                global $DB;
                ++$prepared;
                $DB = $alternate;
            };
            $this->exception(static fn () => (new Computer_Item())->add([
                'computers_id' => $computer->getID(), 'itemtype' => 'Monitor', 'items_id' => $monitor->getID(),
            ]))->isInstanceOf(TransactionOwnershipMismatch::class);
            $this->integer($prepared)->isIdenticalTo(1);
            $this->object($DB)->isIdenticalTo($alternate);
            $this->integer((int)$connection->fetchOne(
                'SELECT COUNT(*) FROM glpi_computers_items WHERE computers_id=?',
                [$computer->getID()]
            ))->isIdenticalTo(0);
            $DB = $original;
            $otherMonitor = $this->createItem(Monitor::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_global' => true]);
            $before = $connection->fetchAllAssociative('SELECT * FROM glpi_computers_items ORDER BY id');
            $candidate = new Computer_Item();
            $mutate = false;
            $mutations = 0;
            $selected = new ComputerItemAdapterProbe();
            $this->calling($selected)->getProvider = $original->getProvider();
            $this->calling($selected)->isSlave = false;
            $this->calling($selected)->getDoctrineConnection = static function () use (
                $connection,
                $candidate,
                $otherMonitor,
                &$mutate,
                &$mutations
            ): Connection {
                if ($mutate) {
                    ++$mutations;
                    $candidate->input['items_id'] = $otherMonitor->getID();
                }
                return $connection;
            };
            $PLUGIN_HOOKS['post_prepareadd']['computer_item_writer_fixture'][Computer_Item::class] = static function (
                Computer_Item $model
            ) use ($candidate, &$mutate): void {
                if ($model === $candidate) {
                    $mutate = true;
                }
            };
            $DB = $selected;
            $input = ['computers_id' => $computer->getID(), 'itemtype' => 'Monitor', 'items_id' => $monitor->getID()];
            $this->exception(static fn () => $candidate->add($input))->isInstanceOf(RuntimeException::class);
            $this->integer($mutations)->isIdenticalTo(1);
            $this->object($DB)->isIdenticalTo($selected);
            $this->array($connection->fetchAllAssociative('SELECT * FROM glpi_computers_items ORDER BY id'))->isIdenticalTo($before);
            $mutate = false;
            unset($PLUGIN_HOOKS['post_prepareadd']['computer_item_writer_fixture']);
            $id = $candidate->add($input);
            $this->integer((int)$id)->isGreaterThan(0);
            $this->integer((int)$candidate->fields['items_id'])->isIdenticalTo((int)$monitor->getID());
            $this->integer((int)$candidate->fields['monitors_id'])->isIdenticalTo((int)$monitor->getID());
            $this->variable($candidate->fields['opaque_parent_id'])->isNull();
            $this->integer((int)$connection->fetchOne(
                'SELECT COUNT(*) FROM glpi_computers_items WHERE computers_id=?',
                [$computer->getID()]
            ))->isIdenticalTo(1);
        } finally {
            $DB = $original;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
            $_SESSION = $session;
        }
    }

    public function testComputerItemFinalAdmissionRejectsWrongPhysicalParentBeforeLoading(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $originalKind = Computer_Item::$itemtype_1;
        $originalTable = ComputerItemFinalLoadParent::getTable();
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)$_SESSION['glpiactive_entity'];
            $computer = $this->createItem(ComputerModel::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
            $monitor = $this->createItem(Monitor::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_global' => true]);
            $connection = $DB->getDoctrineConnection();
            $before = $connection->fetchAllAssociative('SELECT * FROM glpi_computers_items ORDER BY id');
            ComputerItemFinalLoadParent::forceTable(ComputerModel::getTable());
            Computer_Item::$itemtype_1 = ComputerItemFinalLoadParent::class;
            ComputerItemFinalLoadParent::$armed = false;
            ComputerItemFinalLoadParent::$loads = 0;
            $candidate = new Computer_Item();
            $prepared = 0;
            $plugins->setValue(null, [...$active, 'computer_item_physical_fixture']);
            $PLUGIN_HOOKS['post_prepareadd']['computer_item_physical_fixture'][Computer_Item::class] = static function (
                Computer_Item $model
            ) use ($candidate, &$prepared): void {
                if ($model === $candidate) {
                    ++$prepared;
                    ComputerItemFinalLoadParent::$armed = true;
                    ComputerItemFinalLoadParent::forceTable(Monitor::getTable());
                }
            };
            $input = ['computers_id' => $computer->getID(), 'itemtype' => 'Monitor', 'items_id' => $monitor->getID()];
            $this->variable($candidate->add($input))->isIdenticalTo(false);
            $this->integer($prepared)->isIdenticalTo(1);
            $this->integer(ComputerItemFinalLoadParent::$loads)->isIdenticalTo(0);
            $this->array($connection->fetchAllAssociative('SELECT * FROM glpi_computers_items ORDER BY id'))->isIdenticalTo($before);
            unset($PLUGIN_HOOKS['post_prepareadd']['computer_item_physical_fixture']);
            ComputerItemFinalLoadParent::forceTable(ComputerModel::getTable());
            $id = $candidate->add($input);
            $this->integer((int)$id)->isGreaterThan(0);
            $this->integer(ComputerItemFinalLoadParent::$loads)->isGreaterThan(0);
            $row = $connection->fetchAssociative('SELECT * FROM glpi_computers_items WHERE id=?', [$id]);
            $this->integer((int)$row['computers_id'])->isIdenticalTo((int)$computer->getID());
            $this->integer((int)$row['monitors_id'])->isIdenticalTo((int)$monitor->getID());
            $this->variable($row['opaque_parent_id'])->isNull();
        } finally {
            ComputerItemFinalLoadParent::$armed = false;
            ComputerItemFinalLoadParent::$loads = 0;
            ComputerItemFinalLoadParent::forceTable($originalTable);
            Computer_Item::$itemtype_1 = $originalKind;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
            $_SESSION = $session;
        }
    }

    public function testComputerItemOwnersRetainBothCloneRolesAndDirectRetargets(): void
    {
        global $DB;
        $session = $_SESSION;
        $connection = $DB->getDoctrineConnection();
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)$_SESSION['glpiactive_entity'];
            $computer = $this->createItem(ComputerModel::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
            $selections = EntityRegistry::discriminatedReferences(Computer_Item::getTable())['items_id']['selections'];
            $replacements = [];
            foreach ([Monitor::class, Peripheral::class, Phone::class, Printer::class] as $kind) {
                $item = $this->createItem($kind, ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_global' => true]);
                $replacement = $this->createItem($kind, ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_global' => true]);
                $replacements[$kind] = $replacement;
                $link = $this->createItem(Computer_Item::class, [
                    'computers_id' => $computer->getID(), 'itemtype' => $kind, 'items_id' => $item->getID(),
                ]);
                // This is Transfer's actual mapped-writer ingress, not a second public add.
                $this->array((new MappedStorage($DB))->update(
                    Computer_Item::getTable(),
                    (int)$link->getID(),
                    ComputerItemRecord::withReference([], $kind, (int)$replacement->getID())
                ))->contains('items_id');
                $this->boolean($link->getFromDB($link->getID()))->isTrue();
                $this->integer((int)$link->fields['computers_id'])->isIdenticalTo((int)$computer->getID());
                $this->integer((int)$link->fields['items_id'])->isIdenticalTo((int)$replacement->getID());
                $this->integer((int)$link->fields[$selections[$kind]['column']])->isIdenticalTo((int)$replacement->getID());
                $this->variable($link->fields['opaque_parent_id'])->isNull();
                foreach ($selections as $otherKind => $selection) {
                    if ($otherKind !== $kind) {
                        $this->variable($link->fields[$selection['column']])->isNull();
                    }
                }
                $cloneId = $replacement->clone(['name' => $this->getUniqueString()]);
                $this->integer((int)$cloneId)->isGreaterThan(0);
                $row = $connection->fetchAssociative(
                    'SELECT * FROM glpi_computers_items WHERE computers_id=? AND itemtype=? AND items_id=?',
                    [$computer->getID(), $kind, $cloneId]
                );
                $this->array($row)->isNotEmpty();
                $this->integer((int)$row[$selections[$kind]['column']])->isIdenticalTo((int)$cloneId);
                $this->variable($row['opaque_parent_id'])->isNull();
            }
            $computerCloneId = $computer->clone(['name' => $this->getUniqueString()]);
            $this->integer((int)$computerCloneId)->isGreaterThan(0);
            foreach ($replacements as $kind => $replacement) {
                $row = $connection->fetchAssociative(
                    'SELECT * FROM glpi_computers_items WHERE computers_id=? AND itemtype=? AND items_id=?',
                    [$computerCloneId, $kind, $replacement->getID()]
                );
                $this->array($row)->isNotEmpty();
                $this->integer((int)$row[$selections[$kind]['column']])->isIdenticalTo((int)$replacement->getID());
                $replacementId = $replacement->getID();
                $this->boolean($replacement->delete(['id' => $replacementId], true))->isTrue();
                $this->integer((int)$connection->fetchOne(
                    'SELECT COUNT(*) FROM glpi_computers_items WHERE itemtype=? AND items_id=?',
                    [$kind, $replacementId]
                ))->isIdenticalTo(0);
            }
            $clonedComputer = new ComputerModel();
            $this->boolean($clonedComputer->getFromDB($computerCloneId))->isTrue();
            $this->boolean($clonedComputer->delete(['id' => $computerCloneId], true))->isTrue();
            $this->integer((int)$connection->fetchOne(
                'SELECT COUNT(*) FROM glpi_computers_items WHERE computers_id=?',
                [$computerCloneId]
            ))->isIdenticalTo(0);
            $this->integer((int)$connection->fetchOne(
                'SELECT COUNT(*) FROM glpi_computers_items WHERE computers_id=?',
                [$computer->getID()]
            ))->isIdenticalTo(4);
        } finally {
            $_SESSION = $session;
        }
    }

    public function testLateComputerItemAddUsesFinalPermissionAndCurrentSharing(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $connection = $DB->getDoctrineConnection();
        $attempting = false;
        $denied = false;
        $replacementId = 0;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)$_SESSION['glpiactive_entity'];
            $computer = $this->createItem(ComputerModel::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
            $otherComputer = $this->createItem(ComputerModel::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
            $source = $this->createItem(Monitor::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_global' => true]);
            $replacement = $this->createItem(Monitor::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_global' => true]);
            $replacementId = (int)$replacement->getID();
            $plugins->setValue(null, [...$active, 'computer_item_late_fixture']);
            $PLUGIN_HOOKS['post_prepareadd']['computer_item_late_fixture'][Computer_Item::class] = static function (
                Computer_Item $model
            ) use (&$attempting, &$replacementId): void {
                if ($attempting) {
                    $model->input = ComputerItemRecord::withReference($model->input, 'Monitor', $replacementId);
                }
            };
            $permissions = [];
            $PLUGIN_HOOKS['item_can']['computer_item_late_fixture'][Monitor::class] = static function (
                Monitor $model
            ) use (&$denied, &$permissions, &$replacementId): void {
                $permissions[] = [(int)$model->getID(), $model->right];
                if ($denied && (int)$model->getID() === $replacementId) {
                    $model->right = 0;
                }
            };
            $input = ['computers_id' => $computer->getID(), 'itemtype' => 'Monitor', 'items_id' => $source->getID()];
            $attempting = true;
            $denied = true;
            $this->boolean((new Computer_Item())->add($input))->isFalse();
            $this->array($permissions)->contains([$replacementId, READ]);
            $this->integer((int)$connection->fetchOne(
                'SELECT COUNT(*) FROM glpi_computers_items WHERE computers_id=?',
                [$computer->getID()]
            ))->isIdenticalTo(0);
            $denied = false;
            $allowed = new Computer_Item();
            $id = $allowed->add($input);
            $this->integer((int)$id)->isGreaterThan(0);
            $this->integer((int)$allowed->fields['items_id'])->isIdenticalTo($replacementId);
            $this->integer((int)$allowed->fields['monitors_id'])->isIdenticalTo($replacementId);
            $this->boolean((new Computer_Item())->add($input))->isFalse();
            $attempting = false;
            $nonglobal = $this->createItem(Monitor::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_global' => false]);
            $this->createItem(Computer_Item::class, [
                'computers_id' => $otherComputer->getID(), 'itemtype' => 'Monitor', 'items_id' => $nonglobal->getID(),
            ]);
            $replacementId = (int)$nonglobal->getID();
            $attempting = true;
            $this->boolean((new Computer_Item())->add($input))->isFalse();
            $this->integer((int)$connection->fetchOne(
                'SELECT COUNT(*) FROM glpi_computers_items WHERE itemtype=? AND items_id=?',
                ['Monitor', $replacementId]
            ))->isIdenticalTo(1);
        } finally {
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
            $_SESSION = $session;
        }
    }


    public function testConnectedComputerDisplayKeepsLinksAndCurrentHookReads(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $connection = $DB->getDoctrineConnection();
        $em = new class ($connection, Orm::configuration($connection->getDatabasePlatform())) extends EntityManager {
            public int $queries = 0;
            public function createQuery(string $dql = ''): Query
            {
                ++$this->queries;
                return parent::createQuery($dql);
            }
        };
        $loads = new class () {
            public int $count = 0;
            public function postLoad(): void
            {
                ++$this->count;
            }
        };
        $em->getEventManager()->addEventListener([Events::postLoad], $loads);
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)$_SESSION['glpiactive_entity'];
            $monitor = $this->createItem(Monitor::class, ['name' => $this->getUniqueString(),
                'entities_id' => $entity, 'is_global' => true]);
            $computers = [];
            $links = [];
            foreach (['First', 'Second', 'Deleted connection'] as $index => $label) {
                $computer = $this->createItem(ComputerModel::class, ['name' => $label . ' ' . $this->getUniqueString(),
                    'entities_id' => $entity, 'serial' => 'Serial ' . $index, 'otherserial' => 'Inventory ' . $index,
                    'comment' => 'Complete computer fields']);
                $computers[(int)$computer->getID()] = $computer;
                $links[] = $this->createItem(Computer_Item::class, ['computers_id' => $computer->getID(),
                    'itemtype' => 'Monitor', 'items_id' => $monitor->getID(), 'is_dynamic' => $index === 1]);
            }
            $this->boolean($DB->update('glpi_computers_items', ['is_deleted' => true], ['id' => $links[2]->getID()]))->isTrue();
            $selected = iterator_to_array($DB->request(['SELECT' => ['id', 'computers_id', 'is_dynamic'],
                'FROM' => 'glpi_computers_items', 'WHERE' => ['itemtype' => 'Monitor',
                    'items_id' => $monitor->getID(), 'is_deleted' => false]]));
            $this->array($selected)->hasSize(2);
            $ids = array_map('intval', array_column($selected, 'computers_id'));
            $linkIds = array_map('intval', array_column($selected, 'id'));
            $repository = new AssetRepository($em);
            $this->array($repository->computerDisplayData([]))->isEmpty();
            $this->integer($em->queries)->isIdenticalTo(0);
            $data = $repository->computerDisplayData([$ids[1], $ids[0], $ids[1], PHP_INT_MAX]);
            $this->array($data)->hasSize(2);
            $this->integer($em->queries)->isIdenticalTo(1);
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->array(array_keys($data[$ids[0]]))->isIdenticalTo([
                'id', 'name', 'serial', 'otherserial', 'is_template', 'is_recursive', 'entities_id',
            ]);
            $managed = $em->find(ComputerEntity::class, $ids[0]);
            $oldSerial = $managed->serial;
            $this->boolean($DB->update('glpi_computers', ['serial' => 'Current writer serial', 'is_template' => true], ['id' => $ids[0]]))->isTrue();
            $this->string($repository->computerDisplayData([$ids[0]])[$ids[0]]['serial'])->isIdenticalTo('Current writer serial');
            $this->string($managed->serial)->isIdenticalTo($oldSerial);
            $this->boolean($em->contains($managed))->isTrue();
            $this->integer($loads->count)->isIdenticalTo(1);
            $this->object($em->getConnection())->isIdenticalTo($connection);
            $level = $connection->getTransactionNestingLevel();

            $activeLinks = $repository->activeComputerConnections('Monitor', (string)$monitor->getID());
            $this->array(array_column($activeLinks, null, 'id'))
                ->isEqualTo(array_column($selected, null, 'id'));
            foreach ($activeLinks as $row) {
                $this->array(array_keys($row))->isIdenticalTo(['id', 'computers_id', 'is_dynamic']);
            }
            $this->array($repository->activeComputerConnections('Monitor', PHP_INT_MAX))->isEmpty();
            $this->array($repository->activeComputerConnections('__missing_connection_type__', (int)$monitor->getID()))->isEmpty();
            $retainedLink = $em->find(ComputerItemRecord::class, (int)$links[0]->getID());
            $retainedLink->is_deleted = true;
            $assertLinkOrder = function () use ($DB, $connection, $repository, $monitor, $linkIds, $level): void {
                $this->object($DB->getDoctrineConnection())->isIdenticalTo($connection);
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
                $originalRead = iterator_to_array($DB->request([
                    'SELECT' => ['id', 'computers_id', 'is_dynamic'], 'FROM' => 'glpi_computers_items',
                    'WHERE' => ['itemtype' => 'Monitor', 'items_id' => $monitor->getID(), 'is_deleted' => false],
                ]));
                $this->array(array_map('intval', array_column($originalRead, 'id')))->isIdenticalTo($linkIds);
                $projectedRead = $repository->activeComputerConnections('Monitor', (int)$monitor->getID());
                $this->array(array_map('intval', array_column($projectedRead, 'id')))->isIdenticalTo($linkIds);
            };
            // Rollback restores the original tuples before the ordered renderer/hook oracle.
            $dynamicFrame = OwnedMutationFrame::begin($connection);
            try {
                $this->boolean($DB->update('glpi_computers_items', ['is_dynamic' => true], ['id' => $links[0]->getID()]))->isTrue();
                $freshLinks = $repository->activeComputerConnections('Monitor', (int)$monitor->getID());
                $this->boolean((bool)array_column($freshLinks, 'is_dynamic', 'id')[$links[0]->getID()])->isTrue();
                $this->boolean((bool)array_column($activeLinks, 'is_dynamic', 'id')[$links[0]->getID()])->isFalse();
                $this->boolean($em->contains($retainedLink))->isTrue();
                $this->boolean($retainedLink->is_deleted)->isTrue();
            } finally {
                $dynamicFrame->rollBack();
            }
            $assertLinkOrder();
            $monitorId = (int)$monitor->getID();
            $firstLinkId = (int)$links[0]->getID();
            Orm::read($DB, function (EntityManager $outer) use ($monitorId, $firstLinkId): void {
                $pending = $outer->find(ComputerItemRecord::class, $firstLinkId);
                $pending->is_dynamic = true;
                $snapshot = Orm::read($GLOBALS['DB'], static fn (EntityManager $inner): array =>
                    (new AssetRepository($inner))->activeComputerConnections('Monitor', $monitorId));
                $this->boolean((bool)array_column($snapshot, 'is_dynamic', 'id')[$firstLinkId])->isFalse();
                $this->boolean($outer->contains($pending))->isTrue();
                $this->boolean($pending->is_dynamic)->isTrue();
            });
            $this->boolean($em->contains($retainedLink))->isTrue();
            $this->boolean($retainedLink->is_deleted)->isTrue();

            $host = $ids[0];
            $hostModel = $computers[$host];
            $expectedLinks = ['Monitor' => [(int)$monitor->getID() => (int)$monitor->getID()]];
            $this->array($repository->linkedItems('Computer', $host))->isIdenticalTo($expectedLinks);
            $factories = \DbTestCase::ormManagerCounter();
            $before = $factories->getValue();
            $this->array($hostModel->getLinkedItems())->isIdenticalTo($expectedLinks);
            // The linked-item projection creates no manager.
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            $before = $factories->getValue();
            $this->array(iterator_to_array(Computer_Item::getDistinctTypes($host)))->isIdenticalTo([['itemtype' => 'Monitor']]);
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            $reader = new ComputerItemReadOperation($connection);
            $this->array($reader->linkedItems('Computer', $host))->isIdenticalTo($expectedLinks);
            $factoryProbe = new class ($connection, new EventManager()) extends ComputerItemConnectionProbe {
                public function createQueryBuilder(): QueryBuilder
                {
                    throw new LogicException('Connection builder factory must not be invoked');
                }
            };
            $mapping = EntityRegistry::computerItemMapping();
            $this->array(AssetRepository::projectedLinkedItems($factoryProbe, $mapping, 'Computer', $host))
                ->isIdenticalTo($expectedLinks);
            $this->array(AssetRepository::projectedComputerItemTypes($factoryProbe, $mapping, $host))
                ->isIdenticalTo([['itemtype' => 'Monitor']]);
            $this->integer($factoryProbe->queries)->isIdenticalTo(2);
            // Deleted connections remain visible to lifecycle identity readers.
            $expectedReverse = ['Computer' => array_combine(array_keys($computers), array_keys($computers))];
            $this->array($monitor->getLinkedItems())->isIdenticalTo($expectedReverse);
            foreach ([Phone::class, Printer::class, Peripheral::class] as $kind) {
                $device = $this->createItem($kind, ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_global' => true]);
                $this->createItem(Computer_Item::class, ['computers_id' => $host, 'itemtype' => $kind, 'items_id' => $device->getID()]);
                $deviceId = (int)$device->getID();
                $expectedLinks[$kind] = [$deviceId => $deviceId];
                $before = $factories->getValue();
                $this->array($device->getLinkedItems())->isIdenticalTo(['Computer' => [$host => $host]]);
                $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            }
            $this->array($reader->linkedItems('Computer', $host))->isIdenticalTo($expectedLinks);
            $this->array(array_keys($expectedLinks))->isIdenticalTo(['Monitor', 'Phone', 'Printer', 'Peripheral']);
            $record = new RecordRepository($em);
            $this->array(iterator_to_array(Computer_Item::getDistinctTypes($host)))->isIdenticalTo(
                $record->distinctValues('glpi_computers_items', 'itemtype', ['computers_id' => $host], 'itemtype')
            );
            // Additional criteria and legacy string IDs retain the generic compiler.
            foreach ([[$host, ['itemtype' => 'Monitor']], [(string)$host, []]] as [$selection, $extra]) {
                $before = $factories->getValue();
                $actualTypes = iterator_to_array(Computer_Item::getDistinctTypes($selection, $extra));
                $this->array($actualTypes)->isIdenticalTo($record->distinctValues('glpi_computers_items', 'itemtype', ['computers_id' => $selection] + $extra, 'itemtype'));
                $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            }
            $replacement = $this->createItem(Monitor::class, [
                'name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_global' => true,
            ]);
            $replacementId = (int)$replacement->getID();
            $this->boolean($DB->insert('glpi_computers_items', [
                'computers_id' => $host, 'itemtype' => 'Monitor', 'monitors_id' => $monitor->getID(),
                'opaque_parent_id' => null, 'is_deleted' => true, 'is_dynamic' => false,
            ]))->isTrue();
            $duplicate = $DB->insertId();
            try {
                $this->array($reader->linkedItems('Computer', $host))->isIdenticalTo($expectedLinks);
                $this->boolean($DB->update('glpi_computers_items', ['monitors_id' => $replacementId], ['id' => $duplicate]))->isTrue();
                $changedLinks = $expectedLinks;
                $changedLinks['Monitor'][$replacementId] = $replacementId;
                $this->array($reader->linkedItems('Computer', $host))->isIdenticalTo($changedLinks);
                $this->array($reader->linkedItems('Computer', $host)['Monitor'])->hasSize(2);
            } finally {
                $DB->delete('glpi_computers_items', ['id' => $duplicate]);
            }
            foreach ([0, -1] as $missing) {
                $this->array($reader->linkedItems('Computer', $missing))->isEmpty();
                $this->array(iterator_to_array(Computer_Item::getDistinctTypes($missing)))->isEmpty();
            }
            $string = Type::getType(Types::STRING);
            $bigint = Type::getType(Types::BIGINT);
            $integer = Type::getType(Types::INTEGER);
            try {
                Type::overrideType(Types::STRING, new class () extends StringType {
                    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'LOWER(' . $sqlExpr . ')';
                    }
                    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
                    {
                        throw new LogicException('Aliased scalar results do not run PHP conversion.');
                    }
                });
                Type::overrideType(Types::BIGINT, new class () extends BigIntType {
                    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return '(' . $sqlExpr . ' + 1000000)';
                    }
                    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): int|string|null
                    {
                        throw new LogicException('Aliased scalar results do not run PHP conversion.');
                    }
                });
                $convertedLinks = [];
                foreach ($expectedLinks as $kind => $targets) {
                    foreach ($targets as $targetId) {
                        $convertedLinks[strtolower($kind)][$targetId + 1000000] = $targetId + 1000000;
                    }
                }
                $this->array($reader->linkedItems('Computer', $host))->isIdenticalTo($convertedLinks);
                $this->array($reader->linkedItems('Computer', $host)['monitor'])->hasKey((int)$monitor->getID() + 1000000);
                // Reverse IDENTITY bypasses the mapped BIGINT SQL converter.
                $this->array($reader->linkedItems('Monitor', (int)$monitor->getID()))->isIdenticalTo($expectedReverse);
                $input = new class () extends StringType {
                    public int $calls = 0;
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        ++$this->calls;
                        return $sqlExpr;
                    }
                    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
                    {
                        return '__missing_connection_type__';
                    }
                };
                Type::overrideType(Types::STRING, $input);
                $this->array($reader->linkedItems('Monitor', (int)$monitor->getID()))->isEmpty();
                $this->integer($input->calls)->isIdenticalTo(1);
                Type::overrideType(Types::STRING, $string);
                Type::overrideType(Types::BIGINT, $bigint);
                Type::overrideType(Types::INTEGER, new class () extends IntegerType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return '(' . $sqlExpr . ' + 1000000)';
                    }
                });
                $this->array($reader->linkedItems('Computer', $host))->isEmpty();
                $this->array(iterator_to_array(Computer_Item::getDistinctTypes($host)))->isEmpty();
                $this->array($record->distinctValues('glpi_computers_items', 'itemtype', ['computers_id' => $host], 'itemtype'))->isEmpty();
            } finally {
                Type::overrideType(Types::STRING, $string);
                Type::overrideType(Types::BIGINT, $bigint);
                Type::overrideType(Types::INTEGER, $integer);
            }
            $events = new EventManager();
            $extended = new ComputerItemConnectionProbe($connection, $events);
            $database = $DB;
            try {
                $this->mockGenerator->orphanize('__construct');
                $adapter = new ComputerItemAdapterProbe();
                $routes = 0;
                $this->calling($adapter)->getDoctrineConnection = static function () use ($extended, &$routes): Connection {
                    ++$routes;
                    return $extended;
                };
                $late = new class ($database) extends ComputerModel {
                    public bool $fail = false;
                    public function __construct(private DBAdapter $selected)
                    {
                    }
                    public function getID()
                    {
                        $GLOBALS['DB'] = $this->selected;
                        if ($this->fail) {
                            throw new LogicException('Identifier callback failure');
                        }
                        return parent::getID();
                    }
                };
                $late->fields = $hostModel->fields;
                $DB = $adapter;
                $queries = $extended->queries;
                $this->array($late->getLinkedItems())->isIdenticalTo($expectedLinks);
                $this->object($DB)->isIdenticalTo($database);
                $this->integer($routes)->isIdenticalTo(1);
                $this->integer($extended->queries - $queries)->isIdenticalTo(1);
                $DB = $adapter;
                $late->fail = true;
                $queries = $extended->queries;
                $this->exception(static fn () => $late->getLinkedItems())->isInstanceOf(LogicException::class)->hasMessage('Identifier callback failure');
                $this->integer($routes)->isIdenticalTo(2);
                $this->integer($extended->queries)->isIdenticalTo($queries);
            } finally {
                $DB = $database;
            }

            $_SESSION['glpiactiveprofile']['monitor'] = READ;
            $_SESSION['glpiactiveprofile']['computer'] = READ;
            $PLUGIN_HOOKS['item_can'] = [];
            $PLUGIN_HOOKS['import_item'] = ['connected_display_fixture' => true];
            $render = function () use ($monitor): array {
                ob_start();
                try {
                    Computer_Item::showForItem($monitor);
                    $html = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $this->integer(preg_match('/<script type="application\/json"[^>]*>(.*?)<\/script>/s', $html, $match))->isIdenticalTo(1);
                $config = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
                return $config['dataSource']['rows'];
            };
            $rows = $render();
            $this->array(array_keys($rows))->isIdenticalTo($linkIds);
            foreach ($selected as $row) {
                $computer = $computers[(int)$row['computers_id']];
                $this->boolean($computer->getFromDB($computer->getID()))->isTrue();
                $this->array($rows[$row['id']])->isIdenticalTo([
                    'name' => $computer->getLink(),
                    'entity' => Dropdown::getDropdownName('glpi_entities', $entity),
                    'serial' => $computer->fields['serial'], 'otherserial' => $computer->fields['otherserial'],
                    'inventory' => Dropdown::getYesNo($row['is_dynamic']),
                ]);
            }
            $this->string($rows[$linkIds[0]]['name'])->contains('&withtemplate=1');
            $deletedFrame = OwnedMutationFrame::begin($connection);
            try {
                $this->boolean($DB->update('glpi_computers_items', ['is_deleted' => true], ['id' => $linkIds[1]]))->isTrue();
                $freshRows = $render();
                $this->array(array_keys($freshRows))->isIdenticalTo([$linkIds[0]]);
                $this->array($rows)->hasSize(2);
                $this->boolean($em->contains($retainedLink))->isTrue();
                $this->boolean($retainedLink->is_deleted)->isTrue();
            } finally {
                $deletedFrame->rollBack();
            }
            $assertLinkOrder();
            $_SESSION['glpiactiveprofile']['monitor'] = 0;
            ob_start();
            try {
                Computer_Item::showForItem($monitor);
                $this->string(ob_get_contents())->isEmpty();
            } finally {
                ob_end_clean();
                $_SESSION['glpiactiveprofile']['monitor'] = READ;
            }
            $_SESSION['glpiactiveprofile']['computer'] = 0;
            $denied = $render();
            foreach ($denied as $row) {
                $this->string($row['name'])->notContains('<a ');
            }
            $_SESSION['glpiactiveprofile']['computer'] = READ;

            $calls = [];
            $plugins->setValue(null, [...$active, 'connected_display_fixture']);
            $PLUGIN_HOOKS['item_can'] = ['connected_display_fixture' => [ComputerModel::class =>
                static function (ComputerModel $computer) use (&$calls, $connection, $ids): void {
                    $calls[] = ['id' => (int)$computer->getID(), 'comment' => $computer->fields['comment'],
                        'serial' => $computer->fields['serial'], 'right' => $computer->right];
                    if ((int)$computer->getID() === $ids[0]) {
                        $connection->update('glpi_computers', ['serial' => 'Later callback serial'], ['id' => $ids[1]]);
                        $computer->right = false;
                    }
                }]];
            $hooked = $render();
            $this->array(array_column($calls, 'id'))->isIdenticalTo($ids);
            $this->array(array_column($calls, 'right'))->isIdenticalTo([READ, READ]);
            $this->array(array_column($calls, 'comment'))->isIdenticalTo(['Complete computer fields', 'Complete computer fields']);
            $this->string($calls[1]['serial'])->isIdenticalTo('Later callback serial');
            $this->string($hooked[$linkIds[0]]['name'])->notContains('<a ');
            $this->string($hooked[$linkIds[1]]['serial'])->isIdenticalTo('Later callback serial');
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $em->getEventManager()->removeEventListener([Events::postLoad], $loads);
            $em->clear();
            $_SESSION = $session;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
    }

    public function testDisconnectPreservesDeviceAutoCleanAndHooks(): void
    {
        global $CFG_GLPI, $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $savedConfig = $CFG_GLPI;
        $savedHooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        $updated = [];
        try {
            foreach (['contact', 'user', 'group', 'location'] as $field) {
                $CFG_GLPI['is_' . $field . '_autoupdate'] = 0;
                $CFG_GLPI['is_' . $field . '_autoclean'] = 1;
            }
            $CFG_GLPI['state_autoupdate_mode'] = 0;
            $CFG_GLPI['state_autoclean_mode'] = -1;
            $plugins->setValue(null, [...$savedPlugins, 'disconnect_fixture']);
            $PLUGIN_HOOKS['item_update']['disconnect_fixture'][Monitor::class] = static function (Monitor $item) use (&$updated): void {
                $updated[] = (int)$item->getID();
            };
            $computer = $this->createItem(ComputerModel::class, ['name' => '_disconnect_owner', 'entities_id' => $entity]);
            $values = [
                'contact' => 'Assigned contact', 'contact_num' => '12345',
                'locations_id' => $this->getNewLocationId(), 'users_id' => $this->getNewUserId(),
                'groups_id' => $this->getNewGroupId(), 'states_id' => $this->getNewStateId(),
            ];
            foreach (['ordinary', 'unchanged', 'global', 'bypass'] as $mode) {
                $monitor = $this->createItem(Monitor::class, [
                    'name' => '_disconnect_' . $mode, 'entities_id' => $entity,
                    'is_global' => (int)($mode === 'global'),
                ] + ($mode === 'unchanged' ? [
                    'contact' => '', 'contact_num' => '',
                    'locations_id' => 0, 'users_id' => 0, 'groups_id' => 0, 'states_id' => 0,
                ] : $values));
                $link = $this->createItem(Computer_Item::class, [
                    'computers_id' => $computer->getID(), 'itemtype' => 'Monitor', 'items_id' => $monitor->getID(),
                ]);
                $this->boolean($monitor->getFromDB($monitor->getID()))->isTrue();
                $storedFields = $monitor->fields;
                $id = (int)$link->getID();
                $updated = [];
                $input = ['id' => $id];
                if ($mode === 'bypass') {
                    $input['_no_auto_action'] = true;
                }
                $this->boolean($link->delete($input, true))->isTrue();
                $this->boolean($link->getFromDB($id))->isFalse();
                $this->boolean($monitor->getFromDB($monitor->getID()))->isTrue();
                if ($mode === 'ordinary') {
                    $this->array($updated)->isIdenticalTo([(int)$monitor->getID()]);
                    $this->string($monitor->getField('contact'))->isEmpty();
                    $this->string($monitor->getField('contact_num'))->isEmpty();
                    foreach (['locations_id', 'users_id', 'groups_id', 'states_id'] as $field) {
                        $this->variable($monitor->getField($field))->isNull();
                    }
                } elseif ($mode === 'unchanged') {
                    $this->array($updated)->isEmpty();
                    $this->array($monitor->fields)->isIdenticalTo($storedFields);
                } else {
                    $this->array($updated)->isEmpty();
                    foreach ($values as $field => $value) {
                        $this->variable($monitor->getField($field))->isEqualTo($value);
                    }
                }
            }
            $this->boolean($computer->getFromDB($computer->getID()))->isTrue();
        } finally {
            $CFG_GLPI = $savedConfig;
            $PLUGIN_HOOKS = $savedHooks;
            $plugins->setValue(null, $savedPlugins);
        }
    }

    public function testDisconnectAutoCleanVetoPreservesConnectionAndParentPurge(): void
    {
        global $CFG_GLPI, $DB, $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $savedConfig = $CFG_GLPI;
        $savedHooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $savedPlugins = $plugins->getValue();
        $attemptingDelete = false;
        $cleanupCalls = [];
        $completed = [];
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        try {
            foreach (['contact', 'user', 'group', 'location'] as $field) {
                $CFG_GLPI['is_' . $field . '_autoupdate'] = 0;
                $CFG_GLPI['is_' . $field . '_autoclean'] = (int)($field === 'contact');
            }
            $CFG_GLPI['state_autoupdate_mode'] = 0;
            $CFG_GLPI['state_autoclean_mode'] = 0;
            $plugins->setValue(null, [...$savedPlugins, 'disconnect_veto_fixture']);
            foreach ([Monitor::class, Peripheral::class, Phone::class, Printer::class] as $type) {
                $PLUGIN_HOOKS['pre_item_update']['disconnect_veto_fixture'][$type] = static function (CommonDBTM $item) use (
                    &$attemptingDelete,
                    &$cleanupCalls,
                    &$mode
                ): void {
                    if (!$attemptingDelete) {
                        return;
                    }
                    $cleanupCalls[] = (int)$item->getID();
                    if ($mode === 'disconnect' || count($cleanupCalls) === 2) {
                        $item->input = [];
                    }
                };
                $PLUGIN_HOOKS['item_update']['disconnect_veto_fixture'][$type] = static function (CommonDBTM $item) use (&$completed): void {
                    $completed[] = (int)$item->getID();
                };
                foreach (['disconnect', 'computer_purge'] as $mode) {
                    $attemptingDelete = false;
                    $cleanupCalls = [];
                    $completed = [];
                    $computer = $this->createItem(ComputerModel::class, [
                        'name' => '_autoclean_veto_' . $type . '_' . $mode,
                        'entities_id' => $entity,
                    ]);
                    $snapshots = [[$computer, $computer->fields]];
                    // The first cleanup in a parent purge succeeds before the second veto.
                    // Its persisted fields must also be restored by the owning deletion.
                    foreach (['first', 'veto'] as $suffix) {
                        $device = $this->createItem($type, [
                            'name' => '_autoclean_veto_' . $type . '_' . $mode . '_' . $suffix,
                            'entities_id' => $entity,
                            'is_global' => 0,
                            'contact' => 'Keep assigned contact',
                            'contact_num' => '12345',
                        ]);
                        $link = $this->createItem(Computer_Item::class, [
                            'computers_id' => $computer->getID(),
                            'itemtype' => $type,
                            'items_id' => $device->getID(),
                        ]);
                        $snapshots[] = [$device, $device->fields];
                        $snapshots[] = [$link, $link->fields];
                    }
                    $attemptingDelete = true;
                    $logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs', 'ORDER' => 'id']));
                    $target = $mode === 'disconnect' ? $link : $computer;
                    $this->boolean($target->delete(['id' => $target->getID()], true))->isFalse();
                    $attemptingDelete = false;
                    $this->array($cleanupCalls)->hasSize($mode === 'computer_purge' ? 2 : 1);
                    $this->array($completed)->isIdenticalTo($mode === 'computer_purge' ? [$cleanupCalls[0]] : []);
                    $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
                    $this->array(iterator_to_array($DB->request([
                        'FROM' => 'glpi_logs',
                        'ORDER' => 'id',
                    ])))->isIdenticalTo($logs);
                    foreach ($snapshots as [$model, $fields]) {
                        $this->boolean($model->getFromDB($fields['id']))->isTrue();
                        $this->array($model->fields)->isIdenticalTo($fields);
                    }
                }
                unset(
                    $PLUGIN_HOOKS['pre_item_update']['disconnect_veto_fixture'][$type],
                    $PLUGIN_HOOKS['item_update']['disconnect_veto_fixture'][$type]
                );
            }
        } finally {
            $CFG_GLPI = $savedConfig;
            $PLUGIN_HOOKS = $savedHooks;
            $plugins->setValue(null, $savedPlugins);
        }
    }

    public function testUnglobalizePreservesOwnedChangesOnRequiredMutationRefusal(): void
    {
        global $DB, $PLUGIN_HOOKS;

        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $activePlugins = $plugins->getValue();
        $attempting = false;
        $mode = '';
        $relationUpdates = 0;
        $createdClones = [];
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $profile = $_SESSION['glpiactiveprofile'];
            $plugins->setValue(null, [...$activePlugins, 'unglobalize_fixture']);
            $PLUGIN_HOOKS['pre_item_update']['unglobalize_fixture'][Computer_Item::class] = static function (
                CommonDBTM $model
            ) use (
                &$attempting,
                &$mode,
                &$relationUpdates
            ): void {
                if ($attempting && ++$relationUpdates === 2 && $mode === 'late_link_veto') {
                    $model->input = [];
                } elseif ($attempting && $mode === 'link_noop') {
                    $model->input = ['id' => $model->getID()];
                }
            };
            foreach ([Monitor::class, Peripheral::class, Phone::class, Printer::class] as $type) {
                $PLUGIN_HOOKS['pre_item_update']['unglobalize_fixture'][$type] = static function (
                    CommonDBTM $model
                ) use (&$attempting, &$mode): void {
                    if ($attempting && $mode === 'asset_veto') {
                        $model->input = [];
                    } elseif ($attempting && $mode === 'asset_noop') {
                        $model->input = ['id' => $model->getID()];
                    }
                };
                $PLUGIN_HOOKS['pre_item_add']['unglobalize_fixture'][$type] = static function (
                    CommonDBTM $model
                ) use (&$attempting, &$mode): void {
                    if ($attempting && $mode === 'clone_veto') {
                        $model->input = [];
                    }
                };
                $PLUGIN_HOOKS['item_add']['unglobalize_fixture'][$type] = static function (
                    CommonDBTM $model
                ) use (&$attempting, &$createdClones): void {
                    if ($attempting) {
                        $createdClones[] = $model;
                    }
                };
                foreach ([
                    'permissions', 'asset_veto', 'asset_noop', 'clone_veto',
                    'link_noop', 'late_link_veto', 'success',
                ] as $mode) {
                    $attempting = false;
                    $_SESSION['glpiactiveprofile'] = $profile;
                    $device = $this->createItem($type, [
                        'name' => '_unglobalize_' . $type . '_' . $mode,
                        'entities_id' => $entity,
                        'is_global' => true,
                        'contact' => 'Shared device contact',
                    ]);
                    $links = [];
                    for ($index = 0; $index < 3; ++$index) {
                        $computer = $this->createItem(ComputerModel::class, [
                            'name' => '_unglobalize_' . $type . '_' . $mode . '_' . $index,
                            'entities_id' => $entity,
                        ]);
                        $link = $this->createItem(Computer_Item::class, [
                            'computers_id' => $computer->getID(),
                            'itemtype' => $type,
                            'items_id' => $device->getID(),
                            'is_dynamic' => $index === 1,
                        ]);
                        $this->boolean($link->getFromDB($link->getID()))->isTrue();
                        $links[] = $link->fields;
                    }
                    $this->boolean($device->getFromDB($device->getID()))->isTrue();
                    $stored = $device->fields;
                    $tables = [
                        ComputerModel::getTable(), $type::getTable(), Computer_Item::getTable(),
                        'glpi_logs', 'glpi_infocoms',
                    ];
                    $snapshot = static function () use ($DB, $tables): array {
                        $rows = [];
                        foreach ($tables as $table) {
                            $rows[$table] = iterator_to_array($DB->request(['FROM' => $table, 'ORDER' => 'id']));
                        }
                        return $rows;
                    };
                    $before = $snapshot();
                    if ($mode === 'permissions') {
                        $_SESSION['glpiactiveprofile']['computer'] = READ;
                        $_SESSION['glpiactiveprofile'][$type::$rightname] = READ | UPDATE;
                        $this->boolean($device->can($device->getID(), UPDATE))->isTrue();
                        $this->boolean((new Computer_Item())->can($links[1]['id'], UPDATE))->isFalse();
                    }
                    $messages = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
                    $relationUpdates = 0;
                    $createdClones = [];
                    $attempting = true;
                    $result = Computer_Item::unglobalizeItem($device);
                    $attempting = false;
                    $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
                    if ($mode !== 'success') {
                        $this->boolean($snapshot() === $before)->isTrue();
                        $this->boolean($result)->isFalse();
                        $this->array($device->fields)->isIdenticalTo($stored);
                        $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] ?? [])->isIdenticalTo($messages[INFO] ?? []);
                        foreach ($createdClones as $clone) {
                            $this->boolean(isset($clone->fields['id']))->isFalse();
                        }
                        if ($mode === 'late_link_veto') {
                            $this->integer($relationUpdates)->isIdenticalTo(2);
                            $this->array($createdClones)->hasSize(2);
                        }
                    } else {
                        $this->boolean($result)->isTrue();
                        $this->boolean((bool)$device->getField('is_global'))->isFalse();
                        $this->array($createdClones)->hasSize(2);
                        $targets = [];
                        foreach ($links as $fields) {
                            $link = new Computer_Item();
                            $this->boolean($link->getFromDB($fields['id']))->isTrue();
                            $targets[] = (int)$link->getField('items_id');
                            $fields['items_id'] = $link->getField('items_id');
                            $ownerColumn = match ($type) {
                                Monitor::class => 'monitors_id',
                                Peripheral::class => 'peripherals_id',
                                Phone::class => 'phones_id',
                                Printer::class => 'printers_id',
                            };
                            $fields[$ownerColumn] = $fields['items_id'];
                            $this->array($link->fields)->isIdenticalTo($fields);
                            $target = new $type();
                            $this->boolean($target->getFromDB($link->getField('items_id')))->isTrue();
                            $this->boolean((bool)$target->getField('is_global'))->isFalse();
                            $this->string($target->getField('contact'))->isIdenticalTo('Shared device contact');
                        }
                        $this->array(array_unique($targets))->hasSize(3);
                        $this->array($targets)->contains((int)$device->getID());
                    }
                }
                unset(
                    $PLUGIN_HOOKS['pre_item_update']['unglobalize_fixture'][$type],
                    $PLUGIN_HOOKS['pre_item_add']['unglobalize_fixture'][$type],
                    $PLUGIN_HOOKS['item_add']['unglobalize_fixture'][$type]
                );
            }
        } finally {
            $_SESSION = $session;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $activePlugins);
        }
    }

    public function testUnglobalizeRejectsChangedWriterBeforeLifecyclePersistence(): void
    {
        global $DB, $PLUGIN_HOOKS;

        $database = $DB;
        $connection = $database->getDoctrineConnection();
        $caller = $connection->captureManagedTransactionScope();
        $level = $connection->getTransactionNestingLevel();
        $session = $_SESSION;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $activePlugins = $plugins->getValue();
        $alternate = $database->getProvider() === 'pgsql'
            ? PostgresConnection::create(['driver' => 'pdo_pgsql', 'serverVersion' => '14.0'])
            : MySQLConnection::create(['driver' => 'pdo_mysql', 'serverVersion' => '8.0.0']);
        $routed = clone $database;
        (new ReflectionProperty(DBAdapter::class, 'doctrine'))->setValue($routed, $alternate);
        $attempting = false;
        $mode = '';
        $triggered = 0;
        $deviceId = 0;
        $replacement = null;
        $clones = [];
        $nested = null;
        $nestedRunning = false;
        $nestedResult = null;
        $nestedDepth = null;
        $returnedDepth = null;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $plugins->setValue(null, [...$activePlugins, 'unglobalize_writer_fixture']);
            $switchRoute = static function (string $phase) use (&$mode, &$triggered, $routed): void {
                if ($mode === $phase && $triggered === 0) {
                    ++$triggered;
                    $GLOBALS['DB'] = $routed;
                }
            };
            $PLUGIN_HOOKS['pre_item_update']['unglobalize_writer_fixture'][Monitor::class] = static function (
                CommonDBTM $model
            ) use (
                &$attempting,
                &$mode,
                &$triggered,
                &$deviceId,
                &$replacement,
                &$nested,
                &$nestedRunning,
                &$nestedResult,
                &$nestedDepth,
                &$returnedDepth,
                $connection,
                $database,
                $switchRoute
            ): void {
                if (!$attempting || $nestedRunning || (int)$model->getID() !== $deviceId) {
                    return;
                }
                $switchRoute('asset_pre_route');
                if ($mode === 'queue_route' && $triggered === 0) {
                    $switchRoute('queue_route');
                    QueuedNotification::forceSendFor(Monitor::class, $model->getID());
                } elseif ($mode === 'replace_scope' && $triggered === 0) {
                    ++$triggered;
                    $connection->rollBack();
                    $replacement = OwnedMutationFrame::begin($connection);
                    $model->fields['_replacement_scope_marker'] = 'retained';
                    $_SESSION['MESSAGE_AFTER_REDIRECT'][INFO][] = 'Retained replacement scope feedback';
                } elseif ($mode === 'nested_success' && $triggered === 0) {
                    ++$triggered;
                    $nestedRunning = true;
                    try {
                        $nestedResult = OwnershipUpdateUnit::run(
                            $database,
                            $nested,
                            $nested->fields,
                            static function () use ($nested, $connection, &$nestedDepth): bool {
                                $nestedDepth = $connection->getTransactionNestingLevel();
                                return $nested->update([
                                    'id' => $nested->getID(),
                                    'contact' => 'Nested owned contact',
                                ]);
                            }
                        );
                        $returnedDepth = $connection->getTransactionNestingLevel();
                    } finally {
                        $nestedRunning = false;
                    }
                }
            };
            $PLUGIN_HOOKS['pre_item_add']['unglobalize_writer_fixture'][Monitor::class] = static function (
                CommonDBTM $model
            ) use (&$attempting, $switchRoute): void {
                if ($attempting) {
                    $switchRoute('clone_pre_route');
                }
            };
            $PLUGIN_HOOKS['pre_item_update']['unglobalize_writer_fixture'][Computer_Item::class] = static function (
                CommonDBTM $model
            ) use (&$attempting, $switchRoute): void {
                if ($attempting) {
                    $switchRoute('link_pre_route');
                }
            };
            $PLUGIN_HOOKS['item_add']['unglobalize_writer_fixture'][Monitor::class] = static function (
                CommonDBTM $model
            ) use (&$attempting, &$clones, $switchRoute): void {
                if ($attempting) {
                    $clones[] = $model;
                    $switchRoute('clone_post_route');
                }
            };
            $PLUGIN_HOOKS['item_update']['unglobalize_writer_fixture'][Monitor::class] = static function (
                CommonDBTM $model
            ) use (&$attempting, &$deviceId, $switchRoute): void {
                if ($attempting && (int)$model->getID() === $deviceId) {
                    $switchRoute('asset_post_route');
                }
            };
            foreach ([
                'asset_pre_route', 'clone_pre_route', 'link_pre_route',
                'clone_post_route', 'asset_post_route', 'queue_route',
                'replace_scope', 'nested_success', 'success',
            ] as $mode) {
                $attempting = false;
                $triggered = 0;
                $clones = [];
                $nestedResult = null;
                $nestedDepth = null;
                $returnedDepth = null;
                $device = $this->createItem(Monitor::class, [
                    'name' => '_unglobalize_writer_' . $mode,
                    'entities_id' => $entity,
                    'is_global' => true,
                    'contact' => 'Shared device contact',
                ]);
                $deviceId = (int)$device->getID();
                $links = [];
                for ($index = 0; $index < 3; ++$index) {
                    $computer = $this->createItem(ComputerModel::class, [
                        'name' => '_unglobalize_writer_' . $mode . '_' . $index,
                        'entities_id' => $entity,
                    ]);
                    $link = $this->createItem(Computer_Item::class, [
                        'computers_id' => $computer->getID(),
                        'itemtype' => Monitor::class,
                        'items_id' => $deviceId,
                        'is_dynamic' => $index === 1,
                    ]);
                    $this->boolean($link->getFromDB($link->getID()))->isTrue();
                    $links[] = $link->fields;
                }
                if ($mode === 'nested_success') {
                    $nested = $this->createItem(Monitor::class, [
                        'name' => '_unglobalize_writer_nested',
                        'entities_id' => $entity,
                        'is_global' => false,
                        'contact' => 'Before nested update',
                    ]);
                    $this->boolean($nested->getFromDB($nested->getID()))->isTrue();
                }
                $this->boolean($device->getFromDB($deviceId))->isTrue();
                $stored = $device->fields;
                $snapshot = static function () use ($database): array {
                    $rows = [];
                    foreach ([
                        ComputerModel::getTable(), Monitor::getTable(), Computer_Item::getTable(),
                        'glpi_logs', 'glpi_infocoms', 'glpi_queuednotifications',
                    ] as $table) {
                        $rows[$table] = iterator_to_array($database->request(['FROM' => $table, 'ORDER' => 'id']));
                    }
                    return $rows;
                };
                $before = $snapshot();
                $messages = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
                $error = null;
                $result = null;
                $attempting = true;
                try {
                    $result = Computer_Item::unglobalizeItem($device);
                } catch (Throwable $failure) {
                    $error = $failure;
                } finally {
                    $DB = $database;
                    $attempting = false;
                }
                $this->boolean($alternate->isConnected())->isFalse();
                if ($mode === 'replace_scope') {
                    try {
                        $this->object($error)->isInstanceOf(MutationRollbackFailure::class);
                        $this->object($error->primary)->isInstanceOf(TransactionOwnershipMismatch::class);
                        $this->object($error->cleanup)->isInstanceOf(TransactionOwnershipMismatch::class);
                        $this->boolean($error->rollbackUnproven)->isTrue();
                        $this->string($device->fields['_replacement_scope_marker'])->isIdenticalTo('retained');
                        $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO])->contains('Retained replacement scope feedback');
                        $this->integer($triggered)->isIdenticalTo(1);
                        $this->object($replacement)->isInstanceOf(OwnedMutationFrame::class);
                        $replacement->assertActive();
                        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level + 1);
                        $this->boolean($snapshot() === $before)->isTrue();
                    } finally {
                        // Only the capability retained by this callback may close its replacement.
                        if ($replacement !== null) {
                            $replacement->rollBack();
                            $replacement = null;
                        }
                    }
                } elseif ($mode !== 'nested_success' && $mode !== 'success') {
                    $this->object($error)->isInstanceOf(TransactionOwnershipMismatch::class);
                    $this->integer($triggered)->isIdenticalTo(1);
                    $this->boolean($snapshot() === $before)->isTrue();
                    $this->array($device->fields)->isIdenticalTo($stored);
                    $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] ?? [])->isIdenticalTo($messages[INFO] ?? []);
                    foreach ($clones as $clone) {
                        $this->boolean(isset($clone->fields['id']))->isFalse();
                    }
                } else {
                    $this->variable($error)->isNull();
                    $this->boolean($result)->isTrue();
                    $this->array($clones)->hasSize(2);
                    $targets = [];
                    foreach ($links as $fields) {
                        $link = new Computer_Item();
                        $this->boolean($link->getFromDB($fields['id']))->isTrue();
                        $targets[] = (int)$link->getField('items_id');
                        $fields['items_id'] = $link->getField('items_id');
                        $fields['monitors_id'] = $fields['items_id'];
                        $this->array($link->fields)->isIdenticalTo($fields);
                        $target = new Monitor();
                        $this->boolean($target->getFromDB($link->getField('items_id')))->isTrue();
                        $this->boolean((bool)$target->getField('is_global'))->isFalse();
                        $this->string($target->getField('contact'))->isIdenticalTo('Shared device contact');
                    }
                    $this->array(array_unique($targets))->hasSize(3);
                    $this->array($targets)->contains($deviceId);
                    if ($mode === 'nested_success') {
                        $this->integer($triggered)->isIdenticalTo(1);
                        $this->boolean($nestedResult)->isTrue();
                        $this->integer($nestedDepth)->isIdenticalTo($level + 2);
                        $this->integer($returnedDepth)->isIdenticalTo($level + 1);
                        $fresh = new Monitor();
                        $this->boolean($fresh->getFromDB($nested->getID()))->isTrue();
                        $this->string($fresh->getField('contact'))->isIdenticalTo('Nested owned contact');
                    } else {
                        $this->integer($triggered)->isIdenticalTo(0);
                    }
                }
                $caller->assertActive();
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
            }
            // These invoke inherited public producers under a real owned frame.
            // Only the extension callbacks vary; the database effect is the oracle.
            foreach ([
                'table_update', 'table_add', 'table_argument_update', 'table_argument_add',
                'history_table_update', 'getter_update', 'persistence_getter_update', 'persistence_connection_update',
            ] as $producerMode) {
                $this->assert($producerMode);
                $source = $this->createItem(Monitor::class, [
                    'name' => '_unglobalize_callback_' . $producerMode,
                    'entities_id' => $entity,
                    'is_global' => false,
                    'contact' => 'Before producer callback',
                ]);
                $this->boolean($source->getFromDB($source->getID()))->isTrue();
                $probe = (object)['armed' => false, 'calls' => 0, 'tableCalls' => 0, 'history' => false];
                $producer = new class () extends Monitor {
                    public static ?Closure $tableCallback = null;

                    public static function getType()
                    {
                        return Monitor::class;
                    }

                    public static function getTable($classname = null)
                    {
                        if (self::$tableCallback !== null) {
                            (self::$tableCallback)();
                        }
                        return 'glpi_monitors';
                    }
                };
                $producer->fields = $source->fields;
                $producer::$tableCallback = static function () use ($probe, $producerMode, $routed): void {
                    if (!$probe->armed) {
                        return;
                    }
                    ++$probe->tableCalls;
                    $inHistory = false;
                    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $frame) {
                        if (($frame['class'] ?? null) === Log::class
                            && ($frame['function'] ?? null) === 'constructHistory') {
                            $inHistory = true;
                        }
                    }
                    $trigger = in_array($producerMode, ['table_update', 'table_add'], true)
                        || (in_array($producerMode, ['table_argument_update', 'table_argument_add'], true)
                            && $probe->tableCalls === 2)
                        || ($producerMode === 'history_table_update' && $inHistory);
                    if ($trigger) {
                        $probe->armed = false;
                        ++$probe->calls;
                        $probe->history = $inHistory;
                        $GLOBALS['DB'] = $routed;
                    }
                };
                $owner = $database;
                if (in_array($producerMode, ['getter_update', 'persistence_getter_update', 'persistence_connection_update'], true)) {
                    $owner = new class ($connection, $probe, $routed, $alternate, $producerMode) extends DBmysql {
                        public function __construct(
                            private Connection $selected,
                            private object $probe,
                            private DBAdapter $routed,
                            private Connection $alternate,
                            private string $mode
                        ) {
                        }

                        public function getDoctrineConnection(): Connection
                        {
                            // Target the producer's own resolution after the lifecycle checks.
                            if ($this->probe->armed && ($this->mode === 'getter_update'
                                || (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? null) === MappedStorage::class)) {
                                $this->probe->armed = false;
                                ++$this->probe->calls;
                                if ($this->mode === 'persistence_connection_update') {
                                    return $this->alternate;
                                }
                                $GLOBALS['DB'] = $this->routed;
                            }
                            return $this->selected;
                        }
                    };
                }
                $before = $snapshot();
                $stored = $producer->fields;
                $error = null;
                try {
                    $DB = $owner;
                    OwnershipUpdateUnit::run(
                        $owner,
                        $producer,
                        $stored,
                        static function () use ($producer, $producerMode, $probe): bool {
                            $producer->fields['contact'] = 'Must not persist through redirected producer';
                            $probe->armed = true;
                            if (in_array($producerMode, ['table_add', 'table_argument_add'], true)) {
                                unset($producer->fields['id']);
                                return $producer->addToDB() !== false;
                            }
                            return $producer->updateInDB(
                                ['contact'],
                                $producerMode === 'history_table_update' ? ['contact' => 'Before producer callback'] : []
                            );
                        },
                        guardWriter: true
                    );
                } catch (Throwable $failure) {
                    $error = $failure;
                } finally {
                    $DB = $database;
                    $probe->armed = false;
                    $producer::$tableCallback = null;
                }
                $this->object($error)->isInstanceOf(TransactionOwnershipMismatch::class);
                $this->integer($probe->calls)->isIdenticalTo(1);
                if (in_array($producerMode, ['table_argument_update', 'table_argument_add'], true)) {
                    $this->integer($probe->tableCalls)->isIdenticalTo(2);
                } elseif ($producerMode === 'history_table_update') {
                    $this->boolean($probe->history)->isTrue();
                }
                $this->boolean($alternate->isConnected())->isFalse();
                $this->boolean($snapshot() === $before)->isTrue();
                $this->array($producer->fields)->isIdenticalTo($stored);
                $caller->assertActive();
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
            }
            $this->stopCase();
            foreach (['fallback_global', 'fallback_connection'] as $fallbackMode) {
                $source = $this->createItem(Monitor::class, [
                    'name' => '_unglobalize_' . $fallbackMode,
                    'entities_id' => $entity,
                    'is_global' => false,
                    'contact' => 'Before fallback query',
                ]);
                $this->boolean($source->getFromDB($source->getID()))->isTrue();
                $probe = (object)['armed' => false, 'calls' => 0];
                $route = static function () use ($probe, $fallbackMode, $connection, $alternate, $routed) {
                    if ($probe->armed) {
                        $probe->armed = false;
                        ++$probe->calls;
                        if ($fallbackMode === 'fallback_connection') {
                            return $alternate;
                        }
                        $GLOBALS['DB'] = $routed;
                    }
                    return $connection;
                };
                $owner = $database->getProvider() === 'pgsql'
                    ? new class ($route) extends DBpgsql {
                        public function __construct(private Closure $route)
                        {
                            $this->connected = true;
                        }

                        public function getDoctrineConnection(): PostgresConnection
                        {
                            return ($this->route)();
                        }
                    }
                : new class ($route) extends DBmysql {
                    public function __construct(private Closure $route)
                    {
                    }

                    public function getDoctrineConnection(): Connection
                    {
                        return ($this->route)();
                    }
                };
                $before = $snapshot();
                $error = null;
                try {
                    $DB = $owner;
                    OwnershipUpdateUnit::run(
                        $owner,
                        $source,
                        $source->fields,
                        static function () use ($owner, $source, $probe): bool {
                            $probe->armed = true;
                            return $owner->queryParams(
                                $owner->getProvider() === 'pgsql'
                                    ? 'UPDATE glpi_monitors SET contact = $1 WHERE id = $2'
                                    : 'UPDATE glpi_monitors SET contact = ? WHERE id = ?',
                                ['Must not persist through redirected adapter', $source->getID()]
                            ) !== false;
                        },
                        guardWriter: true
                    );
                } catch (Throwable $failure) {
                    $error = $failure;
                } finally {
                    $DB = $database;
                    $probe->armed = false;
                }
                $this->object($error)->isInstanceOf(TransactionOwnershipMismatch::class);
                $this->integer($probe->calls)->isIdenticalTo(1);
                $this->boolean($alternate->isConnected())->isFalse();
                $this->boolean($snapshot() === $before)->isTrue();
                $caller->assertActive();
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
            }
            // A separately supplied reader must not be mistaken for the owned writer.
            $readerAdapter = clone $routed;
            $readerAdapter->slave = true;
            $readConnection = null;
            $this->boolean(OwnershipUpdateUnit::run(
                $database,
                $device,
                $device->fields,
                static function () use ($readerAdapter, &$readConnection): bool {
                    $reader = Orm::create($readerAdapter);
                    try {
                        $readConnection = $reader->getConnection();
                        return true;
                    } finally {
                        $reader->clear();
                    }
                },
                guardWriter: true
            ))->isTrue();
            $this->object($readConnection)->isIdenticalTo($alternate);
            $this->boolean($alternate->isConnected())->isFalse();
            $caller->assertActive();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $DB = $database;
            $attempting = false;
            if ($replacement !== null) {
                $replacement->rollBack();
            }
            $alternate->close();
            $_SESSION = $session;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $activePlugins);
        }
    }

    public function testRelationPermissionsRetainDeclaredViewAndEquivalentOwnerRoles(): void
    {
        $savedSession = $_SESSION;
        try {
            $this->login();
            $this->setEntity(0, true);
            $name = 'relation-rights-' . parent::getUniqueString();
            $computer = $this->createItem('Computer', ['name' => $name, 'entities_id' => 0]);
            $monitor = $this->createItem('Monitor', ['name' => $name, 'entities_id' => 0, 'is_global' => true]);
            $connection = $this->createItem('Computer_Item', ['computers_id' => (int)$computer->getID(), 'itemtype' => 'Monitor', 'items_id' => (int)$monitor->getID()]);
            $id = (int)$connection->getID();
            $_SESSION['glpiactiveprofile']['computer'] = READ;
            $_SESSION['glpiactiveprofile']['monitor'] = READ;
            $this->boolean($connection->canCreateItem())->isFalse('A secondary VIEW grant cannot replace the primary operation grant');
            $this->boolean($connection->canUpdateItem())->isFalse();
            $this->boolean($connection->can($id, UPDATE))->isFalse();
            $_SESSION['glpiactiveprofile']['computer'] = READ | UPDATE;
            $_SESSION['glpiactiveprofile']['monitor'] = 0;
            $this->boolean($connection->canCreateItem())->isFalse('The explicit secondary VIEW role still requires Monitor READ');
            $this->boolean($connection->can($id, UPDATE))->isFalse();
            $_SESSION['glpiactiveprofile']['monitor'] = READ;
            $this->boolean($monitor->can($monitor->getID(), UPDATE))->isFalse();
            $this->boolean($connection->canCreateItem())->isTrue();
            $this->boolean($connection->can($id, UPDATE))->isTrue('Secondary READ is sufficient for its declared VIEW role');

            // Certificate_Item declares the same operation role at both ends.
            // Its existing either-owner fallback and forced-both policy remain.
            $certificate = $this->createItem('Certificate', ['name' => $name, 'entities_id' => 0]);
            $membership = $this->createItem('Certificate_Item', ['certificates_id' => (int)$certificate->getID(), 'itemtype' => 'Computer', 'items_id' => (int)$computer->getID()]);
            $_SESSION['glpiactiveprofile']['certificate'] = READ;
            $this->boolean($membership->canCreateItem())->isTrue();
            $this->boolean($membership->canRelationItem('canUpdateItem', 'canUpdate', true, true))->isFalse('Forced-both admission requires both actual owners writable');
            $_SESSION['glpiactiveprofile']['certificate'] = READ | UPDATE;
            $_SESSION['glpiactiveprofile']['computer'] = READ;
            $this->boolean($membership->canCreateItem())->isTrue('The first equivalent owner may also own the write');
            $this->boolean($membership->canRelationItem('canUpdateItem', 'canUpdate', true, true))->isFalse();
            $_SESSION['glpiactiveprofile']['computer'] = READ | UPDATE;
            $this->boolean($membership->canRelationItem('canUpdateItem', 'canUpdate', true, true))->isTrue();
        } finally {
            $_SESSION = $savedSession;
        }
    }

    protected function getUniqueString()
    {
        $string = parent::getUniqueString();
        $string .= "with a ' inside!";
        return $string;
    }

    private function getNewStateId(): int
    {
        $id = (new State())->add(['name' => $this->getUniqueString()]);
        $this->integer((int)$id)->isGreaterThan(0);
        return (int)$id;
    }

    private function getNewLocationId(): int
    {
        $id = (new Location())->add(['name' => $this->getUniqueString()]);
        $this->integer((int)$id)->isGreaterThan(0);
        return (int)$id;
    }

    private function getNewGroupId(): int
    {
        $id = (new Group())->add(['name' => $this->getUniqueString()]);
        $this->integer((int)$id)->isGreaterThan(0);
        return (int)$id;
    }

    private function getNewUserId(): int
    {
        $id = (new User())->add(['name' => 'asset-user-' . parent::getUniqueString()]);
        $this->integer((int)$id)->isGreaterThan(0);
        return (int)$id;
    }

    private function getNewComputer()
    {
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $fields   = $computer->fields;
        unset($fields['id']);
        unset($fields['date_creation']);
        unset($fields['date_mod']);
        $fields['name'] = $this->getUniqueString();
        $this->integer((int)$computer->add(\Toolbox::addslashes_deep($fields)))->isGreaterThan(0);
        return $computer;
    }

    private function getNewPrinter()
    {
        $printer  = getItemByTypeName('Printer', '_test_printer_all');
        $pfields  = $printer->fields;
        unset($pfields['id']);
        unset($pfields['date_creation']);
        unset($pfields['date_mod']);
        $pfields['name'] = $this->getUniqueString();
        $this->integer((int)$printer->add(\Toolbox::addslashes_deep($pfields)))->isGreaterThan(0);
        return $printer;
    }

    public function testUpdate()
    {
        global $CFG_GLPI;
        $saveconf = $CFG_GLPI;

        $computer = $this->getNewComputer();
        $printer  = $this->getNewPrinter();

        // Create the link
        $link = new \Computer_Item();
        $in = ['computers_id' => $computer->getField('id'),
               'itemtype'     => $printer->getType(),
               'items_id'     => $printer->getID(),
        ];
        $this->integer((int)$link->add($in))->isGreaterThan(0);

        // Change the computer
        $CFG_GLPI['is_contact_autoupdate']  = 1;
        $CFG_GLPI['is_user_autoupdate']     = 1;
        $CFG_GLPI['is_group_autoupdate']    = 1;
        $CFG_GLPI['state_autoupdate_mode']  = -1;
        $CFG_GLPI['is_location_autoupdate'] = 1;
        $in = ['id'           => $computer->getField('id'),
               'contact'      => $this->getUniqueString(),
               'contact_num'  => $this->getUniqueString(),
               'users_id'     => $this->getNewUserId(),
               'groups_id'    => $this->getNewGroupId(),
               'states_id'    => $this->getNewStateId(),
               'locations_id' => $this->getNewLocationId(),
        ];
        $this->boolean($computer->update(\Toolbox::addslashes_deep($in)))->isTrue();
        $this->boolean($computer->getFromDB($computer->getID()))->isTrue();
        $this->boolean($printer->getFromDB($printer->getID()))->isTrue();
        unset($in['id']);
        foreach ($in as $k => $v) {
            // Check the computer new values
            $this->variable($computer->getField($k))->isEqualTo($v);
            // Check the printer and test propagation occurs
            $this->variable($printer->getField($k))->isEqualTo($v);
        }

        //reset values
        $in = ['id'           => $computer->getField('id'),
               'contact'      => '',
               'contact_num'  => '',
               'users_id'     => 0,
               'groups_id'    => 0,
               'states_id'    => 0,
               'locations_id' => 0,
        ];
        $this->boolean($computer->update($in))->isTrue();
        $this->boolean($computer->getFromDB($computer->getID()))->isTrue();
        $this->boolean($printer->getFromDB($printer->getID()))->isTrue();
        unset($in['id']);
        foreach ($in as $k => $v) {
            // Check the computer new values
            $this->variable($computer->getField($k))->isEqualTo($v);
            // Check the printer and test propagation occurs
            $this->variable($printer->getField($k))->isEqualTo($v);
        }

        // Change the computer again
        $CFG_GLPI['is_contact_autoupdate']  = 0;
        $CFG_GLPI['is_user_autoupdate']     = 0;
        $CFG_GLPI['is_group_autoupdate']    = 0;
        $CFG_GLPI['state_autoupdate_mode']  = 0;
        $CFG_GLPI['is_location_autoupdate'] = 0;
        $in2 = ['id'          => $computer->getField('id'),
               'contact'      => $this->getUniqueString(),
               'contact_num'  => $this->getUniqueString(),
               'users_id'     => $this->getNewUserId(),
               'groups_id'    => $this->getNewGroupId(),
               'states_id'    => $this->getNewStateId(),
               'locations_id' => $this->getNewLocationId(),
        ];
        $this->boolean($computer->update(\Toolbox::addslashes_deep($in2)))->isTrue();
        $this->boolean($computer->getFromDB($computer->getID()))->isTrue();
        $this->boolean($printer->getFromDB($printer->getID()))->isTrue();
        unset($in2['id']);
        foreach ($in2 as $k => $v) {
            // Check the computer new values
            $this->variable($computer->getField($k))->isEqualTo($v);
            // Check the printer and test propagation DOES NOT occurs
            $this->variable($printer->getField($k))->isEqualTo($in[$k]);
        }

        // Restore configuration
        $computer = $this->getNewComputer();
        $CFG_GLPI = $saveconf;

        //update devices
        $cpu = new \DeviceProcessor();
        $cpuid = $cpu->add(
            [
              'designation'  => 'Intel(R) Core(TM) i5-4210U CPU @ 1.70GHz',
              'frequence'    => '1700'
         ]
        );

        $this->integer((int)$cpuid)->isGreaterThan(0);

        $link = new \Item_DeviceProcessor();
        $linkid = $link->add(
            [
              'items_id'              => $computer->getID(),
              'itemtype'              => \Computer::getType(),
              'deviceprocessors_id'   => $cpuid,
              'locations_id'          => $computer->getField('locations_id'),
              'states_id'             => $computer->getField('states_id'),
         ]
        );

        $this->integer((int)$linkid)->isGreaterThan(0);

        // Change the computer
        $CFG_GLPI['state_autoupdate_mode']  = -1;
        $CFG_GLPI['is_location_autoupdate'] = 1;
        $in = ['id'           => $computer->getField('id'),
               'states_id'    => $this->getNewStateId(),
               'locations_id' => $this->getNewLocationId(),
        ];
        $this->boolean($computer->update($in))->isTrue();
        $this->boolean($computer->getFromDB($computer->getID()))->isTrue();
        $this->boolean($link->getFromDB($link->getID()))->isTrue();
        unset($in['id']);
        foreach ($in as $k => $v) {
            // Check the computer new values
            $this->variable($computer->getField($k))->isEqualTo($v);
            // Check the printer and test propagation occurs
            $this->variable($link->getField($k))->isEqualTo($v);
        }

        //reset
        $in = ['id'           => $computer->getField('id'),
               'states_id'    => 0,
               'locations_id' => 0,
        ];
        $this->boolean($computer->update($in))->isTrue();
        $this->boolean($computer->getFromDB($computer->getID()))->isTrue();
        $this->boolean($link->getFromDB($link->getID()))->isTrue();
        unset($in['id']);
        foreach ($in as $k => $v) {
            // Check the computer new values
            $this->variable($computer->getField($k))->isEqualTo($v);
            // Check the printer and test propagation occurs
            $this->variable($link->getField($k))->isEqualTo($v);
        }

        // Change the computer again
        $CFG_GLPI['state_autoupdate_mode']  = 0;
        $CFG_GLPI['is_location_autoupdate'] = 0;
        $in2 = ['id'          => $computer->getField('id'),
               'states_id'    => $this->getNewStateId(),
               'locations_id' => $this->getNewLocationId(),
        ];
        $this->boolean($computer->update($in2))->isTrue();
        $this->boolean($computer->getFromDB($computer->getID()))->isTrue();
        $this->boolean($link->getFromDB($link->getID()))->isTrue();
        unset($in2['id']);
        foreach ($in2 as $k => $v) {
            // Check the computer new values
            $this->variable($computer->getField($k))->isEqualTo($v);
            // Check the printer and test propagation DOES NOT occurs
            $this->variable($link->getField($k))->isEqualTo($in[$k]);
        }

        // Restore configuration
        $CFG_GLPI = $saveconf;
    }

    /**
     * Checks that newly created links inherits locations, status, and so on
     *
     * @return void
     */
    public function testCreateLinks()
    {
        global $CFG_GLPI;

        $computer = $this->getNewComputer();
        $saveconf = $CFG_GLPI;

        $CFG_GLPI['is_contact_autoupdate']  = 1;
        $CFG_GLPI['is_user_autoupdate']     = 1;
        $CFG_GLPI['is_group_autoupdate']    = 1;
        $CFG_GLPI['state_autoupdate_mode']  = -1;
        $CFG_GLPI['is_location_autoupdate'] = 1;

        // Change the computer
        $in = ['id'           => $computer->getField('id'),
               'contact'      => $this->getUniqueString(),
               'contact_num'  => $this->getUniqueString(),
               'users_id'     => $this->getNewUserId(),
               'groups_id'    => $this->getNewGroupId(),
               'states_id'    => $this->getNewStateId(),
               'locations_id' => $this->getNewLocationId(),
        ];
        $this->boolean($computer->update(\Toolbox::addslashes_deep($in)))->isTrue();
        $this->boolean($computer->getFromDB($computer->getID()))->isTrue();

        $printer = new \Printer();
        $pid = $printer->add(
            [
              'name'         => 'A test printer',
              'entities_id'  => $computer->getField('entities_id')
         ]
        );

        $this->integer((int)$pid)->isGreaterThan(0);

        // Create the link
        $link = new \Computer_Item();
        $in2 = ['computers_id' => $computer->getField('id'),
               'itemtype'     => $printer->getType(),
               'items_id'     => $printer->getID(),
        ];
        $this->integer((int)$link->add($in2))->isGreaterThan(0);

        $this->boolean($printer->getFromDB($printer->getID()))->isTrue();
        unset($in['id']);
        foreach ($in as $k => $v) {
            // Check the computer new values
            $this->variable($computer->getField($k))->isEqualTo($v);
            // Check the printer and test propagation occurs
            $this->variable($printer->getField($k))->isEqualTo($v);
        }

        //create devices
        $cpu = new \DeviceProcessor();
        $cpuid = $cpu->add(
            [
              'designation'  => 'Intel(R) Core(TM) i5-4210U CPU @ 1.70GHz',
              'frequence'    => '1700'
         ]
        );

        $this->integer((int)$cpuid)->isGreaterThan(0);

        $link = new \Item_DeviceProcessor();
        $linkid = $link->add(
            [
              'items_id'              => $computer->getID(),
              'itemtype'              => \Computer::getType(),
              'deviceprocessors_id'   => $cpuid
         ]
        );

        $this->integer((int)$linkid)->isGreaterThan(0);

        $in3 = ['states_id'    => $in['states_id'],
                'locations_id' => $in['locations_id'],
        ];

        $this->boolean($link->getFromDB($link->getID()))->isTrue();
        foreach ($in3 as $k => $v) {
            // Check the computer new values
            $this->variable($computer->getField($k))->isEqualTo($v);
            // Check the printer and test propagation occurs
            $this->variable($link->getField($k))->isEqualTo($v);
        }

        // Restore configuration
        $CFG_GLPI = $saveconf;
    }

    public function testGetFromIter()
    {
        global $DB;

        // DbTestCase rolls back these owned records after this method.
        $names = [];
        foreach (['first', 'second'] as $suffix) {
            $name = $this->getUniqueString() . ' ' . $suffix;
            $id = (new ComputerModel())->add(Toolbox::addslashes_deep([
                'entities_id' => 0,
                'name' => $name,
            ]));
            $this->integer((int)$id)->isGreaterThan(0);
            $names[(int)$id] = $name;
        }
        $iter = $DB->request([
            'SELECT' => 'id',
            'FROM' => 'glpi_computers',
            'WHERE' => ['id' => array_keys($names)],
            'ORDER' => 'id ASC',
        ]);
        $this->integer(count($iter))->isIdenticalTo(2);
        foreach ($iter as $row) {
            $this->array($row)->hasSize(1)->hasKey('id');
        }
        $prev = false;
        $retrieved = [];
        foreach (\Computer::getFromIter($iter) as $comp) {
            $this->object($comp)->isInstanceOf('Computer');
            $this->array($comp->fields)
               ->hasKey('name')
               ->string['name']->isNotEqualTo($prev);
            $this->string($comp->fields['name'])->isIdenticalTo($names[(int)$comp->getID()]);
            $prev = $comp->fields['name'];
            $retrieved[] = (int)$comp->getID();
        }
        $this->boolean((bool)$prev)->isTrue(); // we are retrieve something
        $this->array($retrieved)->isIdenticalTo(array_keys($names))->hasSize(2);

        // A nullable stored name is legitimate; an ID-only iterator must reload
        // the other persisted fields without replacing NULL with an empty name.
        $marker = $this->getUniqueString();
        $manager = Orm::create($DB);
        try {
            $nullableId = (new RecordWriter($manager))->insert(
                'glpi_computers',
                ['entities_id' => 0, 'name' => null, 'serial' => $marker]
            );
        } finally {
            $manager->clear();
        }
        $this->integer($nullableId)->isGreaterThan(0);
        $nullableIter = $DB->request([
            'SELECT' => 'id',
            'FROM' => 'glpi_computers',
            'WHERE' => ['id' => $nullableId],
        ]);
        $this->integer(count($nullableIter))->isIdenticalTo(1);
        foreach ($nullableIter as $row) {
            $this->array($row)->hasSize(1)->hasKey('id');
        }
        $nullableCount = 0;
        foreach (ComputerModel::getFromIter($nullableIter) as $comp) {
            $this->object($comp)->isInstanceOf('Computer');
            $this->integer((int)$comp->getID())->isIdenticalTo($nullableId);
            $this->array($comp->fields)->hasKeys(['name', 'serial']);
            $this->variable($comp->fields['name'])->isNull();
            $this->string($comp->fields['serial'])->isIdenticalTo($marker);
            ++$nullableCount;
        }
        $this->integer($nullableCount)->isIdenticalTo(1);
    }

    public function testGetFromDbByCrit()
    {
        $comp = new \Computer();
        $this->boolean($comp->getFromDBByCrit(['name' => '_test_pc01']))->isTrue();
        $this->string($comp->getField('name'))->isIdenticalTo('_test_pc01');

        $this->when(
            function () use ($comp) {
                $this->boolean($comp->getFromDBByCrit(['name' => ['LIKE', '_test%']]))->isFalse();
            }
        )->error()
           ->withType(E_USER_WARNING)
           ->withMessage('getFromDBByCrit expects to get one result, 8 found.')
           ->exists();
    }

    public function testClone()
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);

        $date = date('Y-m-d H:i:s');
        $_SESSION['glpi_currenttime'] = $date;

        // Test item cloning
        $computer = $this->getNewComputer();
        $id = $computer->fields['id'];

        //add note
        $note = new \Notepad();
        $this->integer(
            $note->add([
              'itemtype'  => 'Computer',
              'items_id'  => $id
         ])
        )->isGreaterThan(0);

        //add os
        $os = new \OperatingSystem();
        $osid = $os->add([
           'name'   => 'My own OS'
        ]);
        $this->integer($osid)->isGreaterThan(0);

        $ios = new \Item_OperatingSystem();
        $this->integer(
            $ios->add([
              'operatingsystems_id' => $osid,
              'itemtype'            => 'Computer',
              'items_id'            => $id,
         ])
        )->isGreaterThan(0);

        //add infocom
        $infocom = new \Infocom();
        $this->integer(
            $infocom->add([
              'itemtype'  => 'Computer',
              'items_id'  => $id
         ])
        )->isGreaterThan(0);

        //add device
        $cpu = new \DeviceProcessor();
        $cpuid = $cpu->add(
            [
              'designation'  => 'Intel(R) Core(TM) i5-4210U CPU @ 1.70GHz',
              'frequence'    => '1700'
         ]
        );

        $this->integer((int)$cpuid)->isGreaterThan(0);

        $link = new \Item_DeviceProcessor();
        $linkid = $link->add(
            [
              'items_id'              => $id,
              'itemtype'              => 'Computer',
              'deviceprocessors_id'   => $cpuid
         ]
        );
        $this->integer((int)$linkid)->isGreaterThan(0);

        //add document
        $document = new \Document();
        $docid = (int)$document->add(['name' => 'Test link document']);
        $this->integer($docid)->isGreaterThan(0);

        $docitem = new \Document_Item();
        $this->integer(
            $docitem->add([
              'documents_id' => $docid,
              'itemtype'     => 'Computer',
              'items_id'     => $id
         ])
        )->isGreaterThan(0);

        //clone!
        $computer = new \Computer(); //$computer->fields contents is already escaped!
        $this->boolean($computer->getFromDB($id))->isTrue();
        $added = $computer->clone();
        $this->integer((int)$added)->isGreaterThan(0);
        $this->integer($added)->isNotEqualTo($computer->fields['id']);

        $clonedComputer = new \Computer();
        $this->boolean($clonedComputer->getFromDB($added))->isTrue();

        $fields = $computer->fields;

        // Check the computers values. Id and dates must be different, everything else must be equal
        foreach ($fields as $k => $v) {
            switch ($k) {
                case 'id':
                    $this->variable($clonedComputer->getField($k))->isNotEqualTo($computer->getField($k));
                    break;
                case 'date_mod':
                case 'date_creation':
                    $dateClone = new \DateTime($clonedComputer->getField($k));
                    $expectedDate = new \DateTime($date);
                    $this->dateTime($dateClone)->isEqualTo($expectedDate);
                    break;
                case 'name':
                    $this->variable($clonedComputer->getField($k))->isEqualTo("{$computer->getField($k)} (copy)");
                    break;
                default:
                    $this->variable($clonedComputer->getField($k))->isEqualTo($computer->getField($k));
            }
        }

        //TODO: would be better to check each Computer::getCloneRelations() ones.
        $relations = [
           \Infocom::class => 1,
           \Notepad::class  => 1,
           \Item_OperatingSystem::class => 1
        ];

        foreach ($relations as $relation => $expected) {
            $this->integer(
                countElementsInTable(
                    $relation::getTable(),
                    [
                     'itemtype'  => 'Computer',
                     'items_id'  => $clonedComputer->fields['id']
               ]
                )
            )->isIdenticalTo($expected);
        }

        //check processor has been cloned
        $this->boolean($link->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $added]))->isTrue();
        $this->boolean($docitem->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $added]))->isTrue();
    }

    public function testCloneWithAutoCreateInfocom()
    {
        global $DB;

        $this->login();
        $this->setEntity('_test_root_entity', true);

        $date = date('Y-m-d H:i:s');
        $_SESSION['glpi_currenttime'] = $date;

        // Test item cloning
        $computer = $this->getNewComputer();
        $id = $computer->fields['id'];

        //add infocom
        $infocom = new \Infocom();
        $this->integer(
            $infocom->add([
              'itemtype'  => 'Computer',
              'items_id'  => $id,
              'buy_date'  => '2021-01-01',
              'use_date'  => '2021-01-02',
              'value'     => '800.00'
         ])
        )->isGreaterThan(0);

        //clone!
        $computer = new \Computer(); //$computer->fields contents is already escaped!
        $this->boolean($computer->getFromDB($id))->isTrue();
        $infocom_auto_create_original = $CFG_GLPI["infocom_auto_create"] ?? 0;
        $CFG_GLPI["infocom_auto_create"] = 1;
        $added = $computer->clone();
        $CFG_GLPI["infocom_auto_create"] = $infocom_auto_create_original;
        $this->integer((int)$added)->isGreaterThan(0);
        $this->integer($added)->isNotEqualTo($computer->fields['id']);

        $clonedComputer = new \Computer();
        $this->boolean($clonedComputer->getFromDB($added))->isTrue();

        $iterator = $DB->request([
           'SELECT' => ['buy_date', 'use_date', 'value'],
           'FROM'   => \Infocom::getTable(),
           'WHERE'  => [
              'itemtype'  => 'Computer',
              'items_id'  => $clonedComputer->fields['id']
           ]
        ]);
        $this->integer($iterator->count())->isEqualTo(1);
        $this->array($iterator->next())->isIdenticalTo([
           'buy_date'  => '2021-01-01',
           'use_date'  => '2021-01-02',
           'value'     => '800.0000' //DB stores 4 decimal places
        ]);
    }

    public function testTransfer()
    {
        $this->login();
        $computer = $this->getNewComputer();
        $cid = $computer->fields['id'];

        $soft = new \Software();
        $softwares_id = $soft->add([
           'name'         => 'GLPI',
           'entities_id'  => $computer->fields['entities_id']
        ]);
        $this->integer($softwares_id)->isGreaterThan(0);

        $version = new \SoftwareVersion();
        $versions_id = $version->add([
           'softwares_id' => $softwares_id,
           'name'         => '9.5'
        ]);
        $this->integer($versions_id)->isGreaterThan(0);

        $link = new \Item_SoftwareVersion();
        $link_id  = $link->add([
           'itemtype'              => 'Computer',
           'items_id'              => $cid,
           'softwareversions_id'   => $versions_id
        ]);
        $this->integer($link_id)->isGreaterThan(0);

        $entities_id = getItemByTypeName('Entity', '_test_child_2', true);
        $oentities_id = (int)$computer->fields['entities_id'];
        $this->integer($entities_id)->isNotEqualTo($oentities_id);

        //transer to another entity
        $transfer = new \Transfer();

        $controller = new MockController();
        $controller->__construct = function () {
            // void
        };

        $ma = new \mock\MassiveAction([], [], 'process', $controller);

        \MassiveAction::processMassiveActionsForOneItemtype(
            $ma,
            $computer,
            [$cid]
        );
        $transfer->moveItems(['Computer' => [$cid]], $entities_id, [$cid, 'keep_software' => 1]);
        unset($_SESSION['glpitransfer_list']);

        $this->boolean($computer->getFromDB($cid))->isTrue();
        $this->integer((int)$computer->fields['entities_id'])->isidenticalTo($entities_id);

        $this->boolean($soft->getFromDB($softwares_id))->isTrue();
        $this->integer($soft->fields['entities_id'])->isidenticalTo($oentities_id);

        global $DB;
        $softwares = $DB->request([
           'FROM'   => \Item_SoftwareVersion::getTable(),
           'WHERE'  => [
              'itemtype'  => 'Computer',
              'items_id'  => $cid
           ]
        ]);
        $this->integer(count($softwares))->isidenticalTo(1);
    }

    public function testNotesRetainTypedOwnerOpaqueAliasesCloneAndPublicPurge(): void
    {
        global $DB;
        $this->login();
        $this->setEntity(0, true);
        $connection = $DB->getDoctrineConnection();
        $prefix = $this->getUniqueString();
        $parent = $this->createItem(ComputerModel::class, ['name' => $prefix, 'entities_id' => 0]);
        $second = $this->createItem(ComputerModel::class, ['name' => $prefix . '-second', 'entities_id' => 0]);
        $rows = [];
        foreach ([1, 2] as $number) {
            $note = $this->createItem(NotepadModel::class, ['itemtype' => ComputerModel::class, 'items_id' => $parent->getID(), 'content' => $prefix]);
            $this->integer((int)$note->fields['computers_id'])->isIdenticalTo((int)$parent->getID());
            $this->variable($note->fields['opaque_parent_id'])->isNull();
            $rows[] = $note;
        }
        $this->integer(NotepadModel::countForItem($parent))->isIdenticalTo(2);
        $this->array(array_column(NotepadModel::getAllForItem($parent), 'id'))->isIdenticalTo([$rows[1]->getID(), $rows[0]->getID()]);
        $this->boolean($rows[0]->update(['id' => $rows[0]->getID(), 'content' => null]))->isTrue();
        $this->boolean($rows[0]->getFromDB($rows[0]->getID()))->isTrue();
        $this->variable($rows[0]->fields['content'])->isNull();
        $connection->update('glpi_notepads', ['content' => 'native fresh', 'users_id_lastupdater' => null, 'date_mod' => null], ['id' => $rows[1]->getID()]);
        $fresh = array_column(NotepadModel::getAllForItem($parent), null, 'id');
        $this->string($fresh[$rows[1]->getID()]['content'])->isIdenticalTo('native fresh');
        $this->variable($fresh[$rows[1]->getID()]['picture'])->isNull();
        $this->variable($fresh[$rows[1]->getID()]['date_mod'])->isNull();
        $independent = Orm::create($DB);
        try {
            $dirty = $independent->find(NoteRecord::class, (int)$rows[1]->getID());
            $dirty->content = 'Unflushed independent note';
            $snapshot = array_column(NotepadModel::getAllForItem($parent), null, 'id');
            $this->string($snapshot[$rows[1]->getID()]['content'])->isIdenticalTo('native fresh');
            $this->boolean($independent->contains($dirty))->isTrue();
            $this->string($dirty->content)->isIdenticalTo('Unflushed independent note');
            Orm::read($DB, function (EntityManager $outer) use ($parent, $rows): void {
                $pending = $outer->find(NoteRecord::class, (int)$rows[1]->getID());
                $pending->content = 'Unflushed outer note';
                $snapshot = array_column(NotepadModel::getAllForItem($parent), null, 'id');
                $this->string($snapshot[$rows[1]->getID()]['content'])->isIdenticalTo('native fresh');
                $this->boolean($outer->contains($pending))->isTrue();
                $this->string($pending->content)->isIdenticalTo('Unflushed outer note');
            });
        } finally {
            $independent->clear();
        }
        $this->integer((int)$rows[0]->fields['users_id_lastupdater'])->isIdenticalTo((int)Session::getLoginUserID());
        $alias = $this->createItem(NotepadModel::class, ['itemtype' => 'computer', 'items_id' => $parent->getID(), 'content' => $prefix . '-alias']);
        $this->string($alias->fields['itemtype'])->isIdenticalTo('computer');
        $this->variable($alias->fields['computers_id'])->isNull();
        $this->integer((int)$alias->fields['opaque_parent_id'])->isIdenticalTo((int)$parent->getID());
        $count = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notepads WHERE itemtype=? AND items_id=?', [ComputerModel::class, $parent->getID()]);
        $this->integer(NotepadModel::countForItem($parent))->isIdenticalTo($count);
        $this->array(NotepadModel::getAllForItem($parent))->hasSize($count);
        $orphan = (int)$connection->fetchOne('SELECT COALESCE(MAX(id),0)+1 FROM glpi_computers');
        foreach ([['computers_id' => $orphan], ['computers_id' => 0], ['computers_id' => -1],
            ['opaque_parent_id' => $parent->getID()], ['itemtype' => null], ['itemtype' => null, 'computers_id' => null]] as $invalid) {
            $this->exception(static fn () => $connection->transactional(static fn () =>
                $connection->update('glpi_notepads', $invalid, ['id' => $rows[0]->getID()])))->isInstanceOf(NoteDatabaseException::class);
        }
        $this->exception(static fn () => $connection->transactional(static fn () =>
            $connection->delete('glpi_computers', ['id' => $parent->getID()])))->isInstanceOf(NoteDatabaseException::class);
        foreach ([null, '', 'PluginOpaqueNoteParent'] as $kind) {
            $note = $this->createItem(NotepadModel::class, ['itemtype' => $kind, 'items_id' => -7, 'content' => $prefix]);
            $this->variable($note->fields['itemtype'])->isIdenticalTo($kind);
            $this->integer((int)$note->fields['opaque_parent_id'])->isIdenticalTo(-7);
            $this->variable($note->fields['computers_id'])->isNull();
            $this->boolean($note->update(['id' => $note->getID(), 'content' => $prefix . '-changed']))->isTrue();
            $this->boolean($note->getFromDB($note->getID()))->isTrue();
            $this->variable($note->fields['itemtype'])->isIdenticalTo($kind);
        }
        $custom = $this->createItem(PluginNotepadOwnershipParent::class, ['name' => $prefix . '-custom', 'entities_id' => 0]);
        $customNote = $this->createItem(NotepadModel::class, ['itemtype' => PluginNotepadOwnershipParent::class, 'items_id' => $custom->getID(), 'content' => $prefix]);
        $this->string($customNote->fields['itemtype'])->isIdenticalTo(PluginNotepadOwnershipParent::class);
        $factory = getItemForItemtype($customNote->fields['itemtype']);
        $this->object($factory)->isInstanceOf(PluginNotepadOwnershipParent::class);
        $this->boolean($factory->getFromDB($custom->getID()))->isTrue();
        $this->integer((int)$customNote->fields['opaque_parent_id'])->isIdenticalTo((int)$factory->getID());
        $this->variable($customNote->fields['computers_id'])->isNull();
        $this->integer(NotepadModel::countForItem($custom))->isIdenticalTo(1);
        $this->array(NotepadModel::getAllForItem($custom))->hasSize(1);
        $software = $this->createItem(SoftwareModel::class, ['name' => $prefix . '-software', 'entities_id' => 0]);
        $version = $this->createItem(VersionModel::class, ['name' => $prefix, 'softwares_id' => $software->getID()]);
        $license = $this->createItem(LicenseModel::class, [
            'name' => $prefix, 'softwares_id' => $software->getID(), 'entities_id' => 0, 'number' => 0,
        ]);
        $installation = $this->createItem(InstallationModel::class, [
            'itemtype' => ComputerModel::class, 'items_id' => $custom->getID(), 'softwareversions_id' => $version->getID(),
        ]);
        $allocation = $this->createItem(AllocationModel::class, [
            'itemtype' => ComputerModel::class, 'items_id' => $custom->getID(), 'softwarelicenses_id' => $license->getID(),
        ]);
        $this->boolean($license->getFromDB($license->getID()))->isTrue();
        $this->integer((int)$license->fields['is_valid'])->isIdenticalTo(0);
        $this->exception(static fn () => $connection->transactional(
            static fn () => $connection->delete('glpi_computers', ['id' => $custom->getID()])
        ))->isInstanceOf(NoteDatabaseException::class);
        global $PLUGIN_HOOKS;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $activePlugins = $plugins->getValue();
        $owner = Orm::create($DB);
        $dirty = $owner->find(ComputerEntity::class, (int)$custom->getID());
        $dirty->name = 'Unflushed custom physical software subject';
        $depth = $connection->getTransactionNestingLevel();
        $bags = [];
        foreach (['glpi_computers', 'glpi_notepads', 'glpi_items_softwarelicenses', 'glpi_items_softwareversions', 'glpi_softwarelicenses', 'glpi_logs'] as $table) {
            $bags[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id');
        }
        try {
            $plugins->setValue(null, [...$activePlugins, 'note_software_subject_fixture']);
            foreach ([false, true] as $throw) {
                $failure = new RuntimeException('Reject custom subject after canonical allocation cleanup');
                $calls = 0;
                $PLUGIN_HOOKS['pre_item_purge']['note_software_subject_fixture'][InstallationModel::class] = static function ($item) use (
                    $connection,
                    $allocation,
                    $installation,
                    $throw,
                    $failure,
                    &$calls
                ): void {
                    if ((int)$item->getID() !== (int)$installation->getID()) {
                        return;
                    }
                    ++$calls;
                    // Licence cleanup has already executed on the same physical writer.
                    if ($connection->fetchOne('SELECT id FROM glpi_items_softwarelicenses WHERE id=?', [$allocation->getID()]) !== false) {
                        throw new RuntimeException('Canonical allocation was not purged before installation');
                    }
                    if ($throw) {
                        throw $failure;
                    }
                    $item->input = false;
                };
                if ($throw) {
                    $this->exception(static fn () => $custom->delete(['id' => $custom->getID()], true))->isIdenticalTo($failure);
                } else {
                    $this->boolean($custom->delete(['id' => $custom->getID()], true))->isFalse();
                }
                $this->integer($calls)->isIdenticalTo(1);
                foreach ($bags as $table => $before) {
                    $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' ORDER BY id'))->isIdenticalTo($before);
                }
                $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
                $this->boolean($owner->contains($dirty))->isTrue();
                $this->string($dirty->name)->isIdenticalTo('Unflushed custom physical software subject');
            }
            $PLUGIN_HOOKS = $hooks;
            $this->boolean($custom->delete(['id' => $custom->getID()], true))->isTrue();
            $this->boolean($customNote->getFromDB($customNote->getID()))->isFalse();
            $this->boolean($installation->getFromDB($installation->getID()))->isFalse();
            $this->boolean($allocation->getFromDB($allocation->getID()))->isFalse();
            $this->boolean($license->getFromDB($license->getID()))->isTrue();
            $this->integer((int)$license->fields['is_valid'])->isIdenticalTo(1);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
            $this->boolean($owner->contains($dirty))->isTrue();
            $this->string($dirty->name)->isIdenticalTo('Unflushed custom physical software subject');
        } finally {
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $activePlugins);
            $owner->clear();
        }
        $stock = $this->createItem(NotepadModel::class, ['itemtype' => ComputerModel::class, 'items_id' => 0, 'content' => $prefix]);
        $this->variable($stock->fields['computers_id'])->isNull();
        $this->variable($stock->fields['opaque_parent_id'])->isNull();
        $this->integer((int)$stock->fields['items_id'])->isIdenticalTo(0);
        $cloned = (int)$parent->clone(['name' => $prefix . '-clone']);
        $this->integer($cloned)->isGreaterThan(0);
        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notepads WHERE itemtype=? AND computers_id=?', [ComputerModel::class, $cloned]))->isIdenticalTo(2);
        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notepads WHERE itemtype=? AND computers_id IS NULL AND opaque_parent_id=?', ['computer', $cloned]))->isIdenticalTo($count - 2);
        $level = error_reporting();
        try {
            error_reporting($level & ~E_USER_DEPRECATED);
            NotepadModel::cloneItem(ComputerModel::class, $parent->getID(), $second->getID());
        } finally {
            error_reporting($level);
        }
        $this->integer(NotepadModel::countForItem($second))->isIdenticalTo($count);
        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notepads WHERE computers_id=?', [$second->getID()]))->isIdenticalTo(2);
        $this->boolean($parent->delete(['id' => $parent->getID()], true))->isTrue();
        foreach ($rows as $note) {
            $this->boolean($note->getFromDB($note->getID()))->isFalse();
        }
        $this->integer(NotepadModel::countForItem($second))->isIdenticalTo($count);
        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_users WHERE id=?', [Session::getLoginUserID()]))->isIdenticalTo(1);
    }

    public function testNoteRetargetUsesBothTypeNoteRightsAndActualFinalWrites(): void
    {
        global $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $this->setEntity(0, true);
            $prefix = $this->getUniqueString();
            $first = $this->createItem(ComputerModel::class, ['name' => $prefix, 'entities_id' => 0]);
            $second = $this->createItem(ComputerModel::class, ['name' => $prefix . '-second', 'entities_id' => 0]);
            $phone = $this->createItem(Phone::class, ['name' => $prefix, 'entities_id' => 0]);
            $note = $this->createItem(NotepadModel::class, ['itemtype' => ComputerModel::class, 'items_id' => $first->getID(), 'content' => $prefix]);
            $_SESSION['glpiactiveprofile']['computer'] = UPDATENOTE;
            $_SESSION['glpiactiveprofile']['phone'] = 0;
            $this->boolean($note->can($note->getID(), UPDATE))->isTrue();
            $this->boolean($note->update(['id' => $note->getID(), 'content' => 'only note rights']))->isTrue();
            $this->boolean($note->update(['id' => $note->getID(), 'items_id' => (string)$second->getID()]))->isTrue();
            $before = $DB->getDoctrineConnection()->fetchAssociative('SELECT * FROM glpi_notepads WHERE id=?', [$note->getID()]);
            $this->boolean($note->update(['id' => $note->getID(), 'itemtype' => Phone::class, 'items_id' => $phone->getID()]))->isFalse();
            $this->hasSessionMessages(INFO, [__('Cannot update item: not enough right on the parent(s) item(s)')]);
            $this->array($DB->getDoctrineConnection()->fetchAssociative('SELECT * FROM glpi_notepads WHERE id=?', [$note->getID()]))->isIdenticalTo($before);
            $prepared = new NotepadPreparedParent();
            $this->boolean($prepared->getFromDB($note->getID()))->isTrue();
            $prepared->finalKind = Phone::class;
            $prepared->finalIdentity = (int)$phone->getID();
            $this->boolean($prepared->update(['id' => $note->getID(), 'content' => 'late denial']))->isFalse();
            $this->integer($prepared->calls)->isIdenticalTo(1);
            $this->hasSessionMessages(INFO, [__('Cannot update item: not enough right on the parent(s) item(s)')]);
            $this->array($DB->getDoctrineConnection()->fetchAssociative('SELECT * FROM glpi_notepads WHERE id=?', [$note->getID()]))->isIdenticalTo($before);
            $prepared = new NotepadPreparedParent();
            $this->boolean($prepared->getFromDB($note->getID()))->isTrue();
            $prepared->cancelParent = true;
            // The proposed same-type move is admitted before the late callback cancels it.
            $this->boolean($prepared->update(['id' => $note->getID(), 'items_id' => $first->getID(), 'content' => 'cancelled parent']))->isTrue();
            $this->integer($prepared->calls)->isIdenticalTo(1);
            $this->boolean($note->getFromDB($note->getID()))->isTrue();
            $this->integer((int)$note->fields['items_id'])->isIdenticalTo((int)$second->getID());
            $this->string($note->fields['content'])->isIdenticalTo('cancelled parent');
            $_SESSION['glpiactiveprofile']['computer'] = 0;
            $_SESSION['glpiactiveprofile']['phone'] = UPDATENOTE;
            $this->boolean($note->update(['id' => $note->getID(), 'itemtype' => Phone::class, 'items_id' => $phone->getID()]))->isFalse();
            $this->hasSessionMessages(INFO, [__('Cannot update item: not enough right on the parent(s) item(s)')]);
            $_SESSION['glpiactiveprofile']['computer'] = UPDATENOTE;
            $this->boolean($note->update(['id' => $note->getID(), 'itemtype' => Phone::class, 'items_id' => $phone->getID()]))->isTrue();
            $this->boolean($note->getFromDB($note->getID()))->isTrue();
            $this->string($note->fields['itemtype'])->isIdenticalTo(Phone::class);
            $this->variable($note->fields['computers_id'])->isNull();
            $this->integer((int)$note->fields['opaque_parent_id'])->isIdenticalTo((int)$phone->getID());
            foreach ([null, '', 'UnknownNoteParent'] as $kind) {
                $this->boolean($note->update(['id' => $note->getID(), 'itemtype' => $kind, 'items_id' => 0]))->isFalse();
                $this->hasSessionMessages(INFO, [__('Cannot update item: not enough right on the parent(s) item(s)')]);
            }
        } finally {
            $_SESSION = $session;
        }
    }
}


/** Routes SQL to the fixture connection while exposing late metadata callbacks. */
class ComputerItemConnectionProbe extends Connection
{
    public int $queries = 0;
    public ?Closure $platformHook = null;

    public function __construct(private Connection $selected, private EventManager $events)
    {
        parent::__construct($selected->getParams(), $selected->getDriver(), $selected->getConfiguration());
    }

    public function getEventManager(): EventManager
    {
        return $this->events;
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        if ($this->platformHook !== null) {
            $hook = $this->platformHook;
            $this->platformHook = null;
            $hook();
        }
        return $this->selected->getDatabasePlatform();
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        ++$this->queries;
        return $this->selected->executeQuery($sql, $params, $types, $qcp);
    }
}

/** Real public note callbacks; no custom parent factory or raw native producer. */
class NotepadPreparedParent extends NotepadModel
{
    public ?string $finalKind = null;
    public int $finalIdentity = 0;
    public bool $cancelParent = false;
    public int $calls = 0;

    public static function getTable($classname = null)
    {
        return NotepadModel::getTable();
    }

    public function pre_updateInDB()
    {
        parent::pre_updateInDB();
        ++$this->calls;
        $columns = ['itemtype', 'items_id', 'computers_id', 'opaque_parent_id'];
        if ($this->cancelParent) {
            $this->updates = array_values(array_diff($this->updates, $columns));
        } elseif ($this->finalKind !== null) {
            $this->fields['itemtype'] = $this->finalKind;
            $this->fields['items_id'] = $this->finalIdentity;
            $this->fields['computers_id'] = $this->finalKind === ComputerModel::class ? $this->finalIdentity : null;
            $this->fields['opaque_parent_id'] = $this->finalKind === ComputerModel::class ? null : $this->finalIdentity;
            $this->updates = array_values(array_unique([...$this->updates, ...$columns]));
        }
    }
}

/** Factory-only role-one fixture; its class name is never persisted as a discriminator. */
class ComputerItemFinalLoadParent extends ComputerModel
{
    public static bool $armed = false;
    public static int $loads = 0;

    public function getFromDB($id)
    {
        if (self::$armed) {
            ++self::$loads;
        }
        return parent::getFromDB($id);
    }
}
