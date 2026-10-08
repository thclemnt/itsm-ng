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

use Computer;
use DbTestCase;
use DeviceMemory;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Query;
use Item_DeviceGeneric as GenericDeviceLink;
use Item_DeviceMemory;
use Item_Devices;
use itsmng\Database\ComponentCountReadOperation;
use itsmng\Database\EntityRegistry;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ComponentRepository;
use LogicException;
use mock\DBmysql as MockDatabase;
use ReflectionProperty;
use Session;

class Item_DeviceGeneric extends DbTestCase
{
    public function componentFamilies(): array
    {
        $families = [];
        foreach (ForeignKeys::relations() as $table => $relations) {
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

            $em = Orm::create($DB);
            try {
                $repository = new ComponentRepository($em);
                $this->integer($repository->countForAsset([$link->getTable()], 'Computer', $assetId))->isIdenticalTo(1);
                $this->integer($repository->countForAsset([$link->getTable(), $link->getTable()], 'Computer', $assetId))->isIdenticalTo(2);
                $this->integer($repository->countForAsset([$link->getTable()], 'Computer', (int)$other->getID()))->isIdenticalTo(1);
                $this->integer($repository->detach($link->getTable(), 'Monitor', $assetId))->isIdenticalTo(0);
                $this->integer($repository->detach($link->getTable(), 'Computer', $assetId))->isIdenticalTo(2);
            } finally {
                $em->clear();
            }
            $typedStock = isset(EntityRegistry::discriminatedReferences($link->getTable())['items_id']['empty_value']);
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

    public function testComponentTabCountOwnsOneManagerAndPreservesCustomDispatch(): void
    {
        global $DB, $GLPI_CACHE, $CFG_GLPI;

        $database = $DB;
        $session = $_SESSION;
        $configuration = $CFG_GLPI;
        $hadAffinities = $GLPI_CACHE->has('item_device_affinities');
        $savedAffinities = $hadAffinities ? $GLPI_CACHE->get('item_device_affinities') : null;
        $manager = null;
        try {
            $this->login();
            $this->setEntity(0, true);
            $_SESSION['glpishow_count_on_tabs'] = 1;
            $asset = $this->createItem(Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
            $other = $this->createItem(Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
            $device = $this->createItem(DeviceMemory::class, ['designation' => $this->getUniqueString(), 'entities_id' => 0]);
            $common = ['devicememories_id' => (int)$device->getID(), 'entities_id' => 0];
            $this->createItem(Item_DeviceMemory::class, $common + ['itemtype' => 'Computer', 'items_id' => (int)$asset->getID()]);
            $this->createItem(Item_DeviceMemory::class, $common + ['itemtype' => 'Computer', 'items_id' => (int)$asset->getID()]);
            $this->createItem(Item_DeviceMemory::class, $common + ['itemtype' => 'Computer', 'items_id' => (int)$asset->getID(), 'is_deleted' => true]);
            $this->createItem(Item_DeviceMemory::class, $common + ['itemtype' => 'Computer', 'items_id' => (int)$other->getID()]);
            $this->createItem(Item_DeviceMemory::class, $common + ['itemtype' => '', 'items_id' => 0]);
            $affinities = array_keys($this->componentFamilies());
            $this->array($affinities)->hasSize(17);
            $GLPI_CACHE->set('item_device_affinities', ['' => $affinities, 'Computer' => $affinities]);
            $tables = array_map(static fn (string $class): string => $class::getTable(), $affinities);
            $criteria = ['items_id' => $asset->getID(), 'itemtype' => 'Computer', 'is_deleted' => 0];
            // Observe actual factory invocations without changing production factory behavior.
            countElementsInTable($tables[0], $criteria); // Warm the selected canonical scope.
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $before = $factories->getValue();
            $legacy = 0;
            foreach ($tables as $table) {
                $legacy += countElementsInTable($table, $criteria);
            }
            $this->integer($legacy)->isIdenticalTo(2);
            $rows = $DB->getDoctrineConnection()->fetchAllAssociative('SELECT * FROM glpi_items_devicememories WHERE devicememories_id = ?', [(int)$device->getID()]);
            $this->array($rows)->hasSize(5);
            $active = array_filter($rows, static fn (array $row): bool => $row['itemtype'] === 'Computer'
                && (int)$row['items_id'] === (int)$asset->getID() && !(bool)$row['is_deleted']);
            $this->integer(count($active))->isIdenticalTo($legacy);

            $this->integer($factories->getValue() - $before)->isIdenticalTo(0, 'Ordinary component counts reuse the warmed canonical manager');
            $tab = new Item_Devices();
            $expected = Item_Devices::createTabEntry(_n('Component', 'Components', Session::getPluralNumber()), $legacy);
            $before = $factories->getValue();
            $connection = $DB->getDoctrineConnection();
            $probe = new ComponentCountQueryProbe($connection);
            $this->mockGenerator->orphanize('__construct');
            $countAdapter = new MockDatabase();
            $this->calling($countAdapter)->getDoctrineConnection = $probe;
            try {
                $DB = $countAdapter;
                $this->string($tab->getTabNameForItem($asset))->isIdenticalTo($expected);
                $this->integer($factories->getValue() - $before)->isIdenticalTo(1);
                $this->array($probe->queries)->hasSize(17);
                $this->integer($probe->builders)->isIdenticalTo(17);
                foreach ($probe->queries as $query) {
                    $this->array($query['params'])->isIdenticalTo([(int)$asset->getID(), 'Computer', false]);
                    $this->array($query['types'])->isIdenticalTo(['bigint', 'string', 'boolean']);
                }
            } finally {
                $DB = $database;
            }

            // Each original COUNT remains a real ORM query on the supplied connection.
            $connection = $DB->getDoctrineConnection();
            $manager = new class ($connection, Orm::configuration($connection->getDatabasePlatform())) extends EntityManager {
                public array $queries = [];
                public function createQuery(string $dql = ''): Query
                {
                    $this->queries[] = $dql;
                    return parent::createQuery($dql);
                }
            };
            $repository = new ComponentRepository($manager);
            $this->integer($repository->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo($legacy);
            $this->array($manager->queries)->hasSize(17);
            foreach ($manager->queries as $index => $query) {
                $this->string($query)->startWith('SELECT COUNT(r.id) FROM ')->notContains(' JOIN ');
                $reference = EntityRegistry::discriminatedReferences($tables[$index])['items_id'] ?? null;
                $class = EntityRegistry::tables()[$tables[$index]];
                $subject = $reference === null ? 'r.items_id' : 'IDENTITY(r.' . $class::referenceAssociation('Computer') . ')';
                $this->string($query)->contains($subject . ' = :asset')->contains('r.itemtype = :kind')->contains('r.is_deleted = :deleted');
                if ($reference !== null) {
                    $this->string($query)->notContains('r.items_id');
                }
            }
            $this->integer($manager->getUnitOfWork()->size())->isIdenticalTo(0);
            $before = $factories->getValue();
            $canonical = new ComponentCountReadOperation($connection);
            try {
                $this->integer($canonical->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo($legacy);
                $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
                $this->boolean((new ReflectionProperty($canonical, 'manager'))->isInitialized($canonical))->isFalse();
                $this->integer($canonical->countForAsset([], 'Computer', (int)$asset->getID()))->isIdenticalTo(0);
                $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
                // A missing optional projection falls back lazily on the same owner.
                $registry = new ReflectionProperty(EntityRegistry::class, 'model');
                $originalModel = $registry->getValue();
                $withoutProjection = $originalModel;
                unset($withoutProjection['component_counts'][$tables[0]]);
                $registry->setValue(null, $withoutProjection);
                try {
                    $this->integer($canonical->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo($legacy);
                    $this->integer($factories->getValue() - $before)->isIdenticalTo(1);
                    $this->integer($canonical->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo($legacy);
                    $this->integer($factories->getValue() - $before)->isIdenticalTo(1);
                } finally {
                    $registry->setValue(null, $originalModel);
                }

            } finally {
                $canonical->close();
            }
            $before = $factories->getValue();
            $unused = new ComponentCountReadOperation($connection);
            $unused->close();
            $unused->close();
            unset($unused);
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            $native = new ComponentCountReadOperation($probe);
            $bigint = Type::getType('bigint');
            $string = Type::getType('string');
            $boolean = Type::getType('boolean');
            $integer = Type::getType('integer');
            try {
                $this->integer($native->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo($legacy);
                $callback = new class ($connection) extends ComponentCountQueryProbe {
                    public bool $armed = false;
                    public int $calls = 0;
                    public function getDatabasePlatform(): AbstractPlatform
                    {
                        if ($this->armed && ++$this->calls === 2) {
                            Type::overrideType('bigint', new class () extends BigIntType {
                                public function convertToDatabaseValueSQL(string $expression, AbstractPlatform $platform): string
                                {
                                    return '(' . $expression . ' * 0 - 1)';
                                }
                            });
                        }
                        return parent::getDatabasePlatform();
                    }
                };
                $callbackCache = $GLPI_CACHE;
                $GLPI_CACHE = null;
                try {
                    $callbackRead = new ComponentCountReadOperation($callback);
                } finally {
                    $GLPI_CACHE = $callbackCache;
                }
                $callback->armed = true;
                try {
                    $this->integer($callbackRead->countForAsset(['glpi_items_devicememories'], 'Computer', (int)$asset->getID()))->isIdenticalTo(0);
                } finally {
                    $callbackRead->close();
                    Type::overrideType('bigint', $bigint);
                }
                $this->integer($native->countForAsset([$tables[0], $tables[0]], 'Computer', (int)$asset->getID()))
                    ->isIdenticalTo($repository->countForAsset([$tables[0], $tables[0]], 'Computer', (int)$asset->getID()));
                $this->integer($native->countForAsset([], 'Computer', (int)$asset->getID()))->isIdenticalTo(0);
                $this->integer($native->countForAsset($tables, 'UnsupportedComponentSubject', (int)$asset->getID()))
                    ->isIdenticalTo($repository->countForAsset($tables, 'UnsupportedComponentSubject', (int)$asset->getID()));
                $activeId = (int)reset($active)['id'];
                $connection->update('glpi_items_devicememories', ['is_deleted' => true], ['id' => $activeId], ['is_deleted' => 'boolean', 'id' => 'bigint']);
                $this->integer($native->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo(1);
                $connection->update('glpi_items_devicememories', ['is_deleted' => false], ['id' => $activeId], ['is_deleted' => 'boolean', 'id' => 'bigint']);
                Type::overrideType('bigint', new class () extends BigIntType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return '(' . $sqlExpr . ' * 0 - 1)';
                    }
                });
                $this->integer($native->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo(0);
                $this->integer($repository->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo(0);
                Type::overrideType('bigint', new class () extends BigIntType {
                    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        throw new LogicException('COUNT must use the raw identifier expression');
                    }
                });
                Type::overrideType('integer', new class () extends IntegerType {
                    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?int
                    {
                        throw new LogicException('Single scalar COUNT must not apply PHP conversion');
                    }
                });
                $this->integer($native->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo($legacy);
                $this->integer($repository->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo($legacy);
                Type::overrideType('bigint', $bigint);
                Type::overrideType('integer', $integer);
                Type::overrideType('string', new class () extends StringType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return "CASE WHEN " . $sqlExpr . " = '' THEN 'no-component-kind' ELSE 'no-component-kind' END";
                    }
                });
                $this->integer($native->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo(0);
                $this->integer($repository->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo(0);
                Type::overrideType('string', $string);
                Type::overrideType('boolean', new class () extends BooleanType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return '(NOT ' . $sqlExpr . ')';
                    }
                });
                $this->integer($native->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo(1);
                $this->integer($repository->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo(1);
                Type::overrideType('boolean', $boolean);
                $extension = new class ($connection) extends ComponentCountQueryProbe {
                    private ?EventManager $events = null;
                    public function getEventManager(): EventManager
                    {
                        return $this->events ??= new EventManager();
                    }
                };
                $local = new ComponentCountReadOperation($extension);
                $listener = new class () {
                    public int $loads = 0;
                    public function loadClassMetadata(): void
                    {
                        ++$this->loads;
                    }
                };
                $extension->getEventManager()->addEventListener([Events::loadClassMetadata], $listener);
                $this->integer($local->countForAsset($tables, 'Computer', (int)$asset->getID()))->isIdenticalTo($legacy);
                $this->integer($listener->loads)->isGreaterThan(0);
                $this->integer($extension->builders)->isIdenticalTo(0);
                $local->close();
            } finally {
                Type::overrideType('bigint', $bigint);
                Type::overrideType('string', $string);
                Type::overrideType('boolean', $boolean);
                Type::overrideType('integer', $integer);
                $native->close();
            }

            $GLPI_CACHE->set('item_device_affinities', ['' => $affinities, 'Computer' => [Item_DeviceMemory::class, Item_DeviceMemory::class]]);
            $this->string($tab->getTabNameForItem($asset))->isIdenticalTo(Item_Devices::createTabEntry(_n('Component', 'Components', Session::getPluralNumber()), 4));
            $_SESSION['glpishow_count_on_tabs'] = 0;
            $before = $factories->getValue();
            $this->string($tab->getTabNameForItem($asset))->isIdenticalTo(Item_Devices::createTabEntry(_n('Component', 'Components', Session::getPluralNumber())));
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            $_SESSION['glpishow_count_on_tabs'] = 1;
            $rights = $_SESSION['glpiactiveprofile']['computer'];
            $_SESSION['glpiactiveprofile']['computer'] = 0;
            $before = $factories->getValue();
            $this->string($tab->getTabNameForItem($asset))->isEmpty();
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            $_SESSION['glpiactiveprofile']['computer'] = $rights;

            // Custom model dispatch still counts through the canonical physical connection.
            $this->integer(countElementsInTable(Item_DeviceMemory::getTable(), $criteria))->isIdenticalTo($legacy);
            $events = [];
            $custom = new class () extends Computer {
                public static array $events = [];
                public static function getType()
                {
                    self::$events[] = 'type';
                    return 'Computer';
                }
                public function getID()
                {
                    self::$events[] = 'id';
                    return parent::getID();
                }
            };
            $customLink = new class () extends Item_DeviceMemory {
                public static array $events = [];
                public static function getTable($classname = null)
                {
                    self::$events[] = 'table';
                    return Item_DeviceMemory::getTable();
                }
            };
            $custom::$events = & $events;
            $customLink::$events = & $events;
            $custom->fields = $asset->fields;
            // Mutable table aliases must not admit extension classes into the core batch.
            $CFG_GLPI['glpitablesitemtype'][$custom::class] = 'glpi_computers';
            $CFG_GLPI['glpitablesitemtype'][$customLink::class] = 'glpi_items_devicememories';
            $GLPI_CACHE->set('item_device_affinities', ['' => $affinities, 'Computer' => [$customLink::class, GenericDeviceLink::class, $customLink::class]]);
            $before = $factories->getValue();
            $this->string($tab->getTabNameForItem($custom))->isIdenticalTo(Item_Devices::createTabEntry(_n('Component', 'Components', Session::getPluralNumber()), 4));
            $this->array($events)->isIdenticalTo(['type', 'type', 'table', 'id', 'type', 'id', 'type', 'table', 'id', 'type']);
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            $events = [];
            $before = $factories->getValue();
            $this->string($tab->getTabNameForItem($asset))->isIdenticalTo(Item_Devices::createTabEntry(_n('Component', 'Components', Session::getPluralNumber()), 4));
            $this->array($events)->isIdenticalTo(['table', 'table']);
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            $GLPI_CACHE->set('item_device_affinities', ['' => $affinities, 'Computer' => [Item_DeviceMemory::class, Item_DeviceMemory::class]]);
            $events = [];
            $before = $factories->getValue();
            $this->string($tab->getTabNameForItem($custom))->isIdenticalTo(Item_Devices::createTabEntry(_n('Component', 'Components', Session::getPluralNumber()), 4));
            $this->array($events)->isIdenticalTo(['type', 'type', 'id', 'type', 'id', 'type']);
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);

            $this->mockGenerator->orphanize('__construct');
            $routed = new MockDatabase();
            $routes = 0;
            $this->calling($routed)->getDoctrineConnection = static function () use ($connection, &$routes) {
                ++$routes;
                return $connection;
            };
            $DB = $routed;
            $GLPI_CACHE->set('item_device_affinities', ['' => $affinities, 'Computer' => $affinities]);
            $before = $factories->getValue();
            $this->string($tab->getTabNameForItem($asset))->isIdenticalTo($expected);
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            $this->integer($routes)->isIdenticalTo(1);
        } finally {
            $manager?->clear();
            $DB = $database;
            $_SESSION = $session;
            $CFG_GLPI = $configuration;
            if ($hadAffinities) {
                $GLPI_CACHE->set('item_device_affinities', $savedAffinities);
            } else {
                $GLPI_CACHE->delete('item_device_affinities');
            }
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

            $entity_id = (int)$source_computer->getEntityID();
            $this->integer((int)$target_computer->getEntityID())->isIdenticalTo($entity_id);
            $this->boolean(Session::haveAccessToEntity($entity_id))->isTrue();
            $this->boolean($source_computer->can($source_computer->getID(), UPDATE))->isTrue();
            $this->boolean($target_computer->can($target_computer->getID(), UPDATE))->isTrue();

            $device = new DeviceMemory();
            $device_id = $device->add([
                'designation'  => 'memory-' . $this->getUniqueString(),
                'size_default' => 2048,
                'entities_id'  => $entity_id,
            ]);
            $this->integer((int)$device_id)->isGreaterThan(0);
            $this->boolean($device->getFromDB($device_id))->isTrue();
            $this->boolean($device->can($device_id, UPDATE))->isTrue();

            $link = new Item_DeviceMemory();
            $initial_link_id = $link->add([
                'itemtype'          => 'Computer',
                'items_id'          => $source_computer->getID(),
                'devicememories_id' => $device_id,
                'entities_id'       => $entity_id,
            ]);
            $this->integer((int)$initial_link_id)->isGreaterThan(0);
            $this->boolean($link->can($initial_link_id, UPDATE))->isTrue();
            $this->boolean($link->can($initial_link_id, DELETE))->isTrue();

            $link_selection_key = Item_DeviceMemory::getForeignKeyField();
            $_POST = ['devices_id' => $device_id];
            Item_Devices::addDevicesFromPOST([
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
            Item_Devices::updateAll($_POST);
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

class ComponentCountQueryProbe extends Connection
{
    public int $builders = 0;
    public array $queries = [];

    public function __construct(private readonly Connection $selected)
    {
        parent::__construct($selected->getParams(), $selected->getDriver(), $selected->getConfiguration());
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        return $this->selected->getDatabasePlatform();
    }

    public function createQueryBuilder(): QueryBuilder
    {
        ++$this->builders;
        return parent::createQueryBuilder();
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
        return $this->selected->executeQuery($sql, $params, $types, $qcp);
    }
}
