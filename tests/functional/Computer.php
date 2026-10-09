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
use Group;
use Location;
use State;
use Toolbox;
use User;
use itsmng\Database\Entity\Computer as ComputerEntity;
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
use Monitor;
use Peripheral;
use Phone;
use Plugin;
use Printer;
use QueuedNotification;
use ReflectionProperty;
use Throwable;
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
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use itsmng\Database\ComputerItemReadOperation;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Entity\ComputerItem as ComputerItemRecord;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\UnsupportedCriteria;
use LogicException;
use mock\DBmysql as ComputerItemAdapterProbe;

/* Test for inc/computer.class.php */

class Computer extends DbTestCase
{
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

            $host = $ids[0];
            $hostModel = $computers[$host];
            $expectedLinks = $repository->linkedItems('Computer', $host);
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
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
                $before = $factories->getValue();
                $this->array($device->getLinkedItems())->isIdenticalTo(['Computer' => [$host => $host]]);
                $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            }
            $expectedLinks = $repository->linkedItems('Computer', $host);
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
            $this->boolean($DB->insert('glpi_computers_items', ['computers_id' => $host, 'itemtype' => 'Monitor',
                'items_id' => $monitor->getID(), 'is_deleted' => true, 'is_dynamic' => false]))->isTrue();
            $duplicate = $DB->insertId();
            try {
                $this->array($reader->linkedItems('Computer', $host))->isIdenticalTo($expectedLinks);
                $this->boolean($DB->update('glpi_computers_items', ['items_id' => PHP_INT_MAX], ['id' => $duplicate]))->isTrue();
                $this->array($reader->linkedItems('Computer', $host))->isIdenticalTo($repository->linkedItems('Computer', $host));
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
                $this->array($reader->linkedItems('Computer', $host))->isIdenticalTo($repository->linkedItems('Computer', $host));
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
                $this->array($repository->linkedItems('Monitor', (int)$monitor->getID()))->isEmpty();
                $this->integer($input->calls)->isIdenticalTo(2);
                Type::overrideType(Types::STRING, $string);
                Type::overrideType(Types::BIGINT, $bigint);
                Type::overrideType(Types::INTEGER, new class () extends IntegerType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return '(' . $sqlExpr . ' + 1000000)';
                    }
                });
                $this->array($reader->linkedItems('Computer', $host))->isEmpty();
                $this->array($repository->linkedItems('Computer', $host))->isEmpty();
                $this->array(iterator_to_array(Computer_Item::getDistinctTypes($host)))->isEmpty();
                $this->array($record->distinctValues('glpi_computers_items', 'itemtype', ['computers_id' => $host], 'itemtype'))->isEmpty();
            } finally {
                Type::overrideType(Types::STRING, $string);
                Type::overrideType(Types::BIGINT, $bigint);
                Type::overrideType(Types::INTEGER, $integer);
            }
            $events = new EventManager();
            $extended = new ComputerItemConnectionProbe($connection, $events);
            $local = new ComputerItemReadOperation($extended);
            $listener = new class () {
                public int $loads = 0;
                public int $clears = 0;
                public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                {
                    if ($event->getClassMetadata()->name === ComputerItemRecord::class) {
                        ++$this->loads;
                        $manager = $event->getEntityManager();
                        $manager->getConfiguration()->addFilter('deny_links', ComputerItemFilter::class);
                        $manager->getFilters()->enable('deny_links');
                    }
                }
                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $events->addEventListener([Events::loadClassMetadata, Events::onClear], $listener);
            $database = $DB;
            try {
                $this->array($local->linkedItems('Computer', $host))->isEmpty();
                $this->integer($listener->loads)->isIdenticalTo(1);
                $local->close();
                unset($local);
                $this->integer($listener->clears)->isIdenticalTo(1);
                $distinct = new ComputerItemReadOperation($extended);
                $this->array($distinct->distinctTypes('glpi_computers_items', 'computers_id', $host))->isEmpty();
                $this->integer($listener->loads)->isIdenticalTo(2);
                unset($distinct);
                $this->integer($listener->clears)->isIdenticalTo(1);
                $events->removeEventListener([Events::loadClassMetadata], $listener);
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
                $this->integer($listener->clears)->isIdenticalTo(2);
                $DB = $adapter;
                $late->fail = true;
                $this->exception(static fn () => $late->getLinkedItems())->isInstanceOf(LogicException::class)->hasMessage('Identifier callback failure');
                $this->integer($routes)->isIdenticalTo(2);
                $this->integer($listener->clears)->isIdenticalTo(3);
                // The original final table lookup occurs after manager/platform callbacks.
                $extended->platformHook = static function (): void {
                    Computer_Item::forceTable('glpi_computervirtualmachines');
                };
                $DB = $adapter;
                try {
                    $this->exception(static fn () => Computer_Item::getDistinctTypes($host))->isInstanceOf(UnsupportedCriteria::class);
                    $this->integer($routes)->isIdenticalTo(3);
                    $this->integer($listener->clears)->isIdenticalTo(3);
                } finally {
                    Computer_Item::forceTable('glpi_computers_items');
                    $extended->platformHook = null;
                }
            } finally {
                $DB = $database;
                $events->removeEventListener([Events::loadClassMetadata, Events::onClear], $listener);
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

class ComputerItemFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return $targetEntity->name === ComputerItemRecord::class ? '1 = 0' : '';
    }
}
