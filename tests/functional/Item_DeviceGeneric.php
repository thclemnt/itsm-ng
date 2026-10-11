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
use CommonDBTM;
use DeviceGraphicCard;
use DeviceNetworkCard;
use Item_DeviceNetworkCard as NetworkCardLink;
use NetworkEquipment;
use NetworkPort as LegacyNetworkPort;
use NetworkPortEthernet;
use Doctrine\DBAL\Exception as DatabaseException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use InvalidArgumentException;
use Item_DeviceGraphicCard as GraphicCardLink;
use Plugin as LegacyPlugin;
use PluginGraphiccardOwnershipParent as GraphicCardCustomParent;
use itsmng\Database\CloneInput;
use itsmng\Database\Entity\ItemDeviceGraphicCard as GraphicCardEntity;
use DbTestCase;
use DeviceMemory;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
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
use itsmng\Database\Repository\ComponentDefinitionRepository;
use LogicException;
use mock\DBmysql as MockDatabase;
use ReflectionProperty;
use Session;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';
require_once dirname(__DIR__) . '/fixtures/graphiccardownership.php';

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
            $reference = EntityRegistry::discriminatedReferences($assigned->getTable())['items_id'] ?? null;
            $selectedStock = null;
            if (isset($reference['fallback_column'])) {
                $this->integer($reference['selections']['Computer']['empty_value'])->isIdenticalTo(0);
                $selectedStock = $this->createItem($linkType, [$column => $deviceId, 'itemtype' => 'Computer',
                    'items_id' => $reference['selections']['Computer']['empty_value'], 'entities_id' => 0]);
            }
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
            $reference = EntityRegistry::discriminatedReferences($link->getTable())['items_id'] ?? null;
            $typedStock = isset($reference['empty_value']) && !isset($reference['fallback_column']);
            $this->boolean($link->getFromDB($assignedId))->isTrue();
            $this->variable($link->fields['itemtype'])->isIdenticalTo($typedStock ? null : '');
            $this->integer((int)$link->fields['items_id'])->isIdenticalTo(0);
            $this->integer((int)$link->fields[$column])->isIdenticalTo($deviceId);
            $this->boolean($deleted->getFromDB($deleted->getID()))->isTrue();
            $this->boolean((bool)$deleted->fields['is_deleted'])->isTrue();

            $this->boolean(ComponentDefinitionRepository::supportsFamily($link, $device->getTable(), $column))->isTrue();
            $this->boolean($device->delete(['id' => $deviceId, '_replace_by' => $replacementId], true))->isTrue();
            $this->boolean($link->getFromDB($assignedId))->isTrue();
            $this->integer((int)$link->fields[$column])->isIdenticalTo($replacementId);
            $this->boolean($link->getFromDB($foreign->getID()))->isTrue();
            $this->boolean($link->getFromDB($unrelated->getID()))->isTrue();
            if ($selectedStock !== null) {
                $this->boolean($selectedStock->getFromDB($selectedStock->getID()))->isTrue();
                $this->variable($selectedStock->fields['itemtype'])->isIdenticalTo('Computer');
                $this->integer((int)$selectedStock->fields['items_id'])->isIdenticalTo(0);
                $this->variable($selectedStock->fields[$reference['selections']['Computer']['column']])->isNull();
                $this->variable($selectedStock->fields[$reference['fallback_column']])->isNull();
                $this->integer((int)$selectedStock->fields[$column])->isIdenticalTo($replacementId);
            }
            if (isset($reference['fallback_column'])) {
                // Definition ownership does not delegate an opaque parent's authority.
                $opaque = $this->createItem($linkType, [$column => $replacementId, 'itemtype' => 'computer',
                    'items_id' => $assetId, 'entities_id' => 0]);
                $opaqueZero = $this->createItem($linkType, [$column => $replacementId, 'itemtype' => 'computer',
                    'items_id' => 0, 'entities_id' => 0]);
                $this->variable($opaqueZero->fields[$reference['selections']['Computer']['column']])->isNull();
                $this->integer((int)$opaqueZero->fields[$reference['fallback_column']])->isIdenticalTo(0);
                $third = $this->createItem($deviceType, ['designation' => $prefix . '-blocked-target', 'entities_id' => 0]);
                $connection = $DB->getDoctrineConnection();
                $table = $link->getTable();
                $beforeOpaqueReplacement = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
                $this->boolean($replacement->delete(['id' => $replacementId, '_replace_by' => $third->getID()], true))->isFalse();
                $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id'))->isIdenticalTo($beforeOpaqueReplacement);
                $this->boolean($replacement->getFromDB($replacementId))->isTrue();
                $this->boolean($opaque->getFromDB($opaque->getID()))->isTrue();
                $this->boolean($opaque->delete(['id' => $opaque->getID()], true))->isTrue();
                $beforeOpaqueZero = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
                $this->boolean($replacement->delete(['id' => $replacementId, '_replace_by' => $third->getID()], true))->isFalse();
                $this->array($connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id'))->isIdenticalTo($beforeOpaqueZero);
                $this->boolean($opaqueZero->getFromDB($opaqueZero->getID()))->isTrue();
            }
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
            $probe = new ScalarReadProbe($connection);
            $this->mockGenerator->orphanize('__construct');
            $countAdapter = new MockDatabase();
            $this->calling($countAdapter)->getDoctrineConnection = $probe;
            try {
                $DB = $countAdapter;
                $this->string($tab->getTabNameForItem($asset))->isIdenticalTo($expected);
                $this->integer($factories->getValue() - $before)->isIdenticalTo(1);
                $this->array($probe->queries)->hasSize(1);
                $this->integer($probe->builders)->isIdenticalTo(17);
                $this->array($probe->queries[0]['params'])->isIdenticalTo(array_merge(...array_fill(0, 17, [(int)$asset->getID(), 'Computer', false])));
                $this->array($probe->queries[0]['types'])->isIdenticalTo(array_merge(...array_fill(0, 17, ['bigint', 'string', 'boolean'])));
                $this->integer(substr_count($probe->queries[0]['sql'], ' UNION ALL '))->isIdenticalTo(16);
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
                if (isset($reference['fallback_column'])) {
                    $fallback = $manager->getClassMetadata($class)->getFieldName($reference['fallback_column']);
                    $subject = 'COALESCE(' . $subject . ', r.' . $fallback . ', 0)';
                }
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
                $callback = new class ($connection) extends ScalarReadProbe {
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
                $queriesBefore = count($probe->queries);
                $this->integer($native->countForAsset([], 'Computer', (int)$asset->getID()))->isIdenticalTo(0);
                $this->integer(count($probe->queries))->isIdenticalTo($queriesBefore);
                $this->integer($native->countForAsset(['glpi_items_devicememories'], 'Computer', 0))->isIdenticalTo(0);
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
                $extension = new class ($connection) extends ScalarReadProbe {
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
    public function testGraphicCardOpenInputAndCloneSlots(): void
    {
        $record = new GraphicCardEntity();
        $this->array($record->normalizeInput(['itemtype' => 'Computer', 'items_id' => 7]))
            ->isIdenticalTo(['itemtype' => 'Computer', 'computers_id' => 7, 'opaque_parent_id' => null]);
        $zero = $record->normalizeInput(['itemtype' => 'Computer', 'items_id' => 0]);
        $this->array($zero)->isIdenticalTo(['itemtype' => 'Computer', 'computers_id' => null, 'opaque_parent_id' => null]);
        $this->array($record->normalizeInput($zero + ['items_id' => 0]))->isIdenticalTo($zero);
        foreach ([null, '', 'computer', 'PluginGraphiccardOwnershipParent'] as $kind) {
            $values = $record->normalizeInput(['itemtype' => $kind, 'items_id' => -7]);
            $this->array($values)->isIdenticalTo(['itemtype' => $kind, 'computers_id' => null, 'opaque_parent_id' => -7]);
            $this->array($record->normalizeInput($values + ['items_id' => -7]))->isIdenticalTo($values);
        }
        foreach ([
            ['itemtype' => 'Computer', 'items_id' => -1],
            ['itemtype' => 'Computer', 'items_id' => null],
            ['itemtype' => 'Computer', 'computers_id' => 7, 'opaque_parent_id' => 7],
            ['itemtype' => null, 'computers_id' => 7, 'opaque_parent_id' => 0],
            ['itemtype' => null, 'opaque_parent_id' => null],
            ['itemtype' => '', 'items_id' => 0, 'opaque_parent_id' => 1],
        ] as $invalid) {
            $this->exception(static fn () => $record->normalizeInput($invalid))->isInstanceOf(InvalidArgumentException::class);
        }
        $source = ['itemtype' => 'Computer', 'items_id' => 7, 'computers_id' => 7, 'opaque_parent_id' => null];
        $copy = CloneInput::merge(GraphicCardLink::getTable(), $source, ['itemtype' => null, 'items_id' => 0]);
        $this->variable($copy['itemtype'])->isNull();
        $this->variable($copy['computers_id'])->isNull();
        $this->integer($copy['opaque_parent_id'])->isIdenticalTo(0);
        $this->integer($copy['items_id'])->isIdenticalTo(0);
    }

    public function testGraphicCardOpenAffinityStockAliasCloneAndPurge(): void
    {
        global $DB, $CFG_GLPI, $GLPI_CACHE;
        $configuration = $CFG_GLPI;
        $session = $_SESSION;
        $reporting = error_reporting();
        $tablesProperty = new ReflectionProperty(CommonDBTM::class, 'tables_of');
        $tables = $tablesProperty->getValue();
        $hadAffinities = $GLPI_CACHE->has('item_device_affinities');
        $affinities = $hadAffinities ? $GLPI_CACHE->get('item_device_affinities') : null;
        error_reporting($reporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        try {
            $this->login();
            $this->setEntity(0, true);
            $prefix = $this->getUniqueString();
            $asset = $this->createItem(Computer::class, ['name' => $prefix . '-asset', 'entities_id' => 0]);
            $other = $this->createItem(Computer::class, ['name' => $prefix . '-other', 'entities_id' => 0]);
            $device = $this->createItem(DeviceGraphicCard::class, ['designation' => $prefix, 'entities_id' => 0]);
            $base = ['devicegraphiccards_id' => (int)$device->getID(), 'entities_id' => 0, 'memory' => 2048];
            $attached = $this->createItem(GraphicCardLink::class, $base + ['itemtype' => 'Computer', 'items_id' => (int)$asset->getID()]);
            $alias = $this->createItem(GraphicCardLink::class, $base + ['itemtype' => 'computer', 'items_id' => (int)$asset->getID()]);
            $nullStock = $this->createItem(GraphicCardLink::class, $base + ['itemtype' => null, 'items_id' => 0]);
            $blankStock = $this->createItem(GraphicCardLink::class, $base + ['itemtype' => '', 'items_id' => 0]);
            $zero = $this->createItem(GraphicCardLink::class, $base + ['itemtype' => 'Computer', 'items_id' => 0]);
            $this->boolean($zero->update(['id' => $zero->getID(), 'memory' => 4096]))->isTrue();
            $connection = $DB->getDoctrineConnection();
            $expected = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 2 : 1;
            $this->integer((int)$connection->fetchOne('SELECT computers_id FROM glpi_items_devicegraphiccards WHERE id=?', [$attached->getID()]))->isIdenticalTo((int)$asset->getID());
            $this->variable($connection->fetchOne('SELECT computers_id FROM glpi_items_devicegraphiccards WHERE id=?', [$alias->getID()]))->isNull();
            $this->integer((int)$connection->fetchOne('SELECT opaque_parent_id FROM glpi_items_devicegraphiccards WHERE id=?', [$alias->getID()]))->isIdenticalTo((int)$asset->getID());
            $em = Orm::create($DB);
            try {
                $repository = new ComponentRepository($em);
                $this->integer($repository->countForAsset([$attached->getTable()], 'Computer', (int)$asset->getID()))->isIdenticalTo($expected);
                $this->integer((int)$repository->nativeCountQueryForAsset($attached->getTable(), 'Computer', (int)$asset->getID())->executeQuery()->fetchOne())->isIdenticalTo($expected);
                $this->integer((int)ComponentRepository::projectedCountQueryForAsset($connection, $attached->getTable(), 'Computer', (int)$asset->getID(), EntityRegistry::componentCountMapping($attached->getTable()))->executeQuery()->fetchOne())->isIdenticalTo($expected);
                $this->array(array_column($repository->stock($attached->getTable(), 'devicegraphiccards_id', (int)$device->getID()), 'id'))->isIdenticalTo([(int)$blankStock->getID()]);
                $this->array(array_column($repository->forDevice($attached->getTable(), 'devicegraphiccards_id', (int)$device->getID(), null, null, null), 'id'))->isIdenticalTo([(int)$nullStock->getID()]);
                $this->array($repository->assigned($attached->getTable(), 'devicegraphiccards_id', 'Computer', (int)$asset->getID(), []))->hasSize($expected);
            } finally {
                $em->clear();
            }
            $group = $attached->getTableGroupRows($device, 'Computer');
            $expectedGroup = [(int)$zero->getID(), (int)$attached->getID()];
            if ($expected === 2) {
                $expectedGroup[] = (int)$alias->getID();
            }
            $this->array(array_column($group, 'id'))->isIdenticalTo($expectedGroup, 'The real root Computer row also admits the zero identity binding');
            $nativeCriteria = $attached->getTableGroupCriteria($device, 'Computer');
            $nativeCriteria['ORDERBY'][] = $attached->getTable() . '.id';
            $nativeGroup = iterator_to_array($DB->request($nativeCriteria), false);
            $orderedColumns = static function (array $row): array {
                ksort($row);
                return $row;
            };
            $this->array(array_map($orderedColumns, $group))->isIdenticalTo(array_map($orderedColumns, $nativeGroup));
            // Real configured plugin extension, using its public forced-table route.
            GraphicCardCustomParent::forceTable(Computer::getTable());
            $CFG_GLPI['glpitablesitemtype'][GraphicCardCustomParent::class] = Computer::getTable();
            $this->boolean(LegacyPlugin::registerClass(GraphicCardCustomParent::class, ['itemdevicegraphiccard_types' => true, 'itemdevices_types' => true]))->isTrue();
            $this->array(GraphicCardLink::itemAffinity())->contains(GraphicCardCustomParent::class);
            $GLPI_CACHE->delete('item_device_affinities');
            $this->array(Item_Devices::getItemAffinities(GraphicCardCustomParent::class))->contains(GraphicCardLink::class);
            $CFG_GLPI['itemdevicegraphiccard_types'] = ['*'];
            $this->array(GraphicCardLink::getConcernedItems())->contains(GraphicCardCustomParent::class);
            $parent = $this->createItem(GraphicCardCustomParent::class, ['name' => $prefix . '-plugin', 'entities_id' => 0]);
            GraphicCardCustomParent::$loads = 0;
            $opaque = $this->createItem(GraphicCardLink::class, $base + ['itemtype' => GraphicCardCustomParent::class, 'items_id' => (int)$parent->getID()]);
            $this->integer(GraphicCardCustomParent::$loads)->isGreaterThan(0);
            $this->array($opaque->getTableGroupRows($device, GraphicCardCustomParent::class))->hasSize(1);
            $this->variable($connection->fetchOne('SELECT computers_id FROM glpi_items_devicegraphiccards WHERE id=?', [$opaque->getID()]))->isNull();
            $this->boolean($opaque->update(['id' => $opaque->getID(), 'itemtype' => 'Computer', 'items_id' => $other->getID()]))->isTrue();
            $this->boolean($opaque->update(['id' => $opaque->getID(), 'itemtype' => GraphicCardCustomParent::class, 'items_id' => $parent->getID()]))->isTrue();
            $cloned = (int)$asset->clone(['name' => $prefix . '-clone']);
            $this->integer($cloned)->isGreaterThan(0);
            $this->array($attached->find(['itemtype' => 'Computer', 'items_id' => $cloned]))->hasSize($expected);
            $this->integer((int)$connection->fetchOne("SELECT COUNT(*) FROM glpi_items_devicegraphiccards WHERE itemtype='computer' AND computers_id IS NULL AND opaque_parent_id=?", [$cloned]))->isIdenticalTo($expected - 1);
            Item_Devices::cloneItem('Computer', $asset->getID(), $other->getID());
            $this->integer((int)$connection->fetchOne("SELECT COUNT(*) FROM glpi_items_devicegraphiccards WHERE itemtype='computer' AND computers_id IS NULL AND opaque_parent_id=?", [$other->getID()]))->isIdenticalTo($expected - 1);
            $this->boolean($asset->delete(['id' => $asset->getID(), 'keep_devices' => 1], true))->isTrue();
            $this->boolean($attached->getFromDB($attached->getID()))->isTrue();
            $this->string($attached->fields['itemtype'])->isIdenticalTo('');
            $this->integer((int)$attached->fields['items_id'])->isIdenticalTo(0);
            $this->variable($attached->fields['computers_id'])->isNull();
            $this->integer((int)$attached->fields['opaque_parent_id'])->isIdenticalTo(0);
            $clonedAsset = new Computer();
            $this->boolean($clonedAsset->getFromDB($cloned))->isTrue();
            $this->boolean($clonedAsset->delete(['id' => $cloned], true))->isTrue();
            $this->array($attached->find(['itemtype' => 'Computer', 'items_id' => $cloned]))->isEmpty();
            $this->boolean($opaque->getFromDB($opaque->getID()))->isTrue();
            $this->variable($nullStock->fields['itemtype'])->isNull();
        } finally {
            $CFG_GLPI = $configuration;
            $_SESSION = $session;
            error_reporting($reporting);
            $tablesProperty->setValue(null, $tables);
            if ($hadAffinities) {
                $GLPI_CACHE->set('item_device_affinities', $affinities);
            } else {
                $GLPI_CACHE->delete('item_device_affinities');
            }
        }
    }

    public function testGraphicCardNativeNullableKindRejectsInvalidOwner(): void
    {
        global $DB;
        $this->login();
        $this->setEntity(0, true);
        $asset = $this->createItem(Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $device = $this->createItem(DeviceGraphicCard::class, ['designation' => $this->getUniqueString(), 'entities_id' => 0]);
        $row = $this->createItem(GraphicCardLink::class, ['devicegraphiccards_id' => $device->getID(), 'itemtype' => null, 'items_id' => 0, 'entities_id' => 0]);
        $connection = $DB->getDoctrineConnection();
        $before = $connection->fetchAssociative('SELECT * FROM glpi_items_devicegraphiccards WHERE id=?', [$row->getID()]);
        foreach ([
            ['itemtype' => null, 'computers_id' => (int)$asset->getID(), 'opaque_parent_id' => null],
            ['itemtype' => null, 'computers_id' => null, 'opaque_parent_id' => null],
            ['itemtype' => 'Computer', 'computers_id' => 0, 'opaque_parent_id' => null],
            ['itemtype' => 'Computer', 'computers_id' => (int)$asset->getID(), 'opaque_parent_id' => 0],
            ['itemtype' => 'Computer', 'computers_id' => -1, 'opaque_parent_id' => null],
            ['itemtype' => 'Computer', 'computers_id' => (int)$connection->fetchOne('SELECT MAX(id)+100 FROM glpi_computers'), 'opaque_parent_id' => null],
        ] as $invalid) {
            $connection->beginTransaction();
            try {
                $this->exception(static fn () => $connection->update('glpi_items_devicegraphiccards', $invalid, ['id' => $row->getID()]))->isInstanceOf(DatabaseException::class);
            } finally {
                $connection->rollBack();
            }
            $this->array($connection->fetchAssociative('SELECT * FROM glpi_items_devicegraphiccards WHERE id=?', [$row->getID()]))->isIdenticalTo($before);
        }
        $connection->beginTransaction();
        try {
            $this->exception(static fn () => $connection->insert('glpi_items_devicegraphiccards', ['devicegraphiccards_id' => (int)$device->getID(), 'itemtype' => null, 'computers_id' => (int)$asset->getID(), 'opaque_parent_id' => null, 'entities_id' => 0]))->isInstanceOf(DatabaseException::class);
        } finally {
            $connection->rollBack();
        }
        $connection->update('glpi_items_devicegraphiccards', ['opaque_parent_id' => -7], ['id' => $row->getID()]);
        $this->integer((int)$connection->fetchOne('SELECT items_id FROM glpi_items_devicegraphiccards WHERE id=?', [$row->getID()]))->isIdenticalTo(-7);
        $connection->update('glpi_items_devicegraphiccards', ['itemtype' => 'Computer', 'computers_id' => (int)$asset->getID(), 'opaque_parent_id' => null], ['id' => $row->getID()]);
        $connection->beginTransaction();
        try {
            $this->exception(static fn () => $connection->delete('glpi_computers', ['id' => $asset->getID()]))->isInstanceOf(DatabaseException::class);
        } finally {
            $connection->rollBack();
        }
        $this->boolean($row->delete(['id' => $row->getID()], true))->isTrue();
        $this->boolean($asset->delete(['id' => $asset->getID()], true))->isTrue();
    }

    public function testGraphicCardPreparedOwnerRetainsActualParentRights(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $this->login();
        $this->setEntity(0, true);
        $first = $this->createItem(Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $second = $this->createItem(Computer::class, ['name' => $this->getUniqueString(), 'entities_id' => 0]);
        $device = $this->createItem(DeviceGraphicCard::class, ['designation' => $this->getUniqueString(), 'entities_id' => 0]);
        $row = $this->createItem(GraphicCardLink::class, ['devicegraphiccards_id' => $device->getID(), 'itemtype' => 'Computer', 'items_id' => $first->getID(), 'entities_id' => 0]);
        $probe = new GraphicCardPreparedOwner();
        $this->boolean($probe->getFromDB($row->getID()))->isTrue();
        $probe->preparedComputer = (int)$second->getID();
        $connection = $DB->getDoctrineConnection();
        $before = $connection->fetchAssociative('SELECT * FROM glpi_items_devicegraphiccards WHERE id=?', [$row->getID()]);
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(LegacyPlugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $denials = 0;
        try {
            $plugins->setValue(null, [...$active, 'graphiccard_parent_fixture']);
            $PLUGIN_HOOKS['item_can']['graphiccard_parent_fixture'][Computer::class] =
                static function (Computer $parent) use ($second, &$denials): void {
                    if ((int)$parent->getID() === (int)$second->getID()) {
                        ++$denials;
                        $parent->right = false;
                    }
                };
            $this->boolean($probe->update(['id' => $row->getID(), 'memory' => 4096]))->isFalse();
            $this->integer($denials)->isGreaterThan(0);
            $this->array($connection->fetchAssociative('SELECT * FROM glpi_items_devicegraphiccards WHERE id=?', [$row->getID()]))->isIdenticalTo($before);
            $this->integer((int)$probe->fields['items_id'])->isIdenticalTo((int)$first->getID());
        } finally {
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
        $this->boolean($probe->update(['id' => $row->getID(), 'memory' => 4096]))->isTrue();
        $this->integer((int)$connection->fetchOne('SELECT computers_id FROM glpi_items_devicegraphiccards WHERE id=?', [$row->getID()]))->isIdenticalTo((int)$second->getID());
        $this->variable($connection->fetchOne('SELECT opaque_parent_id FROM glpi_items_devicegraphiccards WHERE id=?', [$row->getID()]))->isNull();
    }
    public function remainingOpenComponentFamilies(): array
    {
        return array_intersect_key($this->componentFamilies(), array_fill_keys([
            'Item_DeviceNetworkCard', 'Item_DeviceGeneric', 'Item_DeviceSoundCard',
            'Item_DeviceFirmware', 'Item_DeviceDrive', 'Item_DeviceControl',
            'Item_DeviceCase', 'Item_DeviceSimcard', 'Item_DevicePci',
        ], true));
    }

    /** @dataProvider remainingOpenComponentFamilies */
    public function testRemainingComponentOpenParentIdentityAndLifecycle(string $linkType, string $deviceType, string $column): void
    {
        global $DB, $CFG_GLPI, $GLPI_CACHE;
        $session = $_SESSION;
        $configuration = $CFG_GLPI;
        $tablesProperty = new ReflectionProperty(CommonDBTM::class, 'tables_of');
        $tables = $tablesProperty->getValue();
        $hadAffinities = $GLPI_CACHE->has('item_device_affinities');
        $affinities = $hadAffinities ? $GLPI_CACHE->get('item_device_affinities') : null;
        try {
            $this->login();
            $this->setEntity(0, true);
            $prefix = $this->getUniqueString();
            $asset = $this->createItem(Computer::class, ['name' => $prefix, 'entities_id' => 0]);
            $other = $this->createItem(Computer::class, ['name' => $prefix . '-other', 'entities_id' => 0]);
            $device = $this->createItem($deviceType, ['designation' => $prefix, 'entities_id' => 0]);
            $base = [$column => $device->getID(), 'entities_id' => 0];
            $link = $this->createItem($linkType, $base + ['itemtype' => 'Computer', 'items_id' => $asset->getID()]);
            $duplicate = $this->createItem($linkType, $base + ['itemtype' => 'Computer', 'items_id' => $asset->getID()]);
            $alias = $this->createItem($linkType, $base + ['itemtype' => 'computer', 'items_id' => $asset->getID()]);
            $blank = $this->createItem($linkType, $base + ['itemtype' => '', 'items_id' => 0]);
            $zero = $this->createItem($linkType, $base + ['itemtype' => 'Computer', 'items_id' => 0]);
            $this->boolean($zero->update(['id' => $zero->getID(), 'serial' => 'free']))->isTrue();
            $table = $link->getTable();
            $entity = EntityRegistry::tables()[$table];
            $record = new $entity();
            $kindProperty = new ReflectionProperty($entity, 'itemtype');
            $nullable = $kindProperty->getType()->allowsNull();
            // Every ORM target agrees with the actual PHP owning-property type.
            $ownerProperty = new ReflectionProperty($entity, 'computer');
            $this->string($ownerProperty->getType()->getName())->isIdenticalTo(EntityRegistry::tables()[Computer::getTable()]);
            $normalized = $record->normalizeInput(['itemtype' => 'Computer', 'items_id' => 0]);
            $this->array($record->normalizeInput($normalized + ['items_id' => 0]))->isIdenticalTo($normalized);
            $this->array($record->normalizeInput(['itemtype' => 'PluginOpaqueParent', 'items_id' => -9]))
                ->isIdenticalTo(['itemtype' => 'PluginOpaqueParent', 'computers_id' => null, 'opaque_parent_id' => -9]);
            foreach ([['itemtype' => 'Computer', 'items_id' => -1], ['itemtype' => 'Computer', 'items_id' => null],
                ['itemtype' => 'Computer', 'computers_id' => $asset->getID(), 'opaque_parent_id' => 0],
                ['itemtype' => null, 'computers_id' => $asset->getID(), 'opaque_parent_id' => 0]] as $invalid) {
                $this->exception(static fn () => $record->normalizeInput($invalid))->isInstanceOf(InvalidArgumentException::class);
            }
            if ($nullable) {
                $nullStock = $this->createItem($linkType, $base + ['itemtype' => null, 'items_id' => 0]);
                $this->variable($nullStock->getField('itemtype'))->isNull();
            } else {
                $this->exception(static fn () => $record->normalizeInput(['itemtype' => null, 'items_id' => 0]))->isInstanceOf(InvalidArgumentException::class);
            }
            $connection = $DB->getDoctrineConnection();
            $this->integer((int)$connection->fetchOne("SELECT computers_id FROM $table WHERE id=?", [$link->getID()]))->isIdenticalTo((int)$asset->getID());
            $this->variable($connection->fetchOne("SELECT computers_id FROM $table WHERE id=?", [$alias->getID()]))->isNull();
            $expected = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 3 : 2;
            $em = Orm::create($DB);
            try {
                $repository = new ComponentRepository($em);
                $this->integer($repository->countForAsset([$table], 'Computer', (int)$asset->getID()))->isIdenticalTo($expected);
                $this->integer((int)$repository->nativeCountQueryForAsset($table, 'Computer', (int)$asset->getID())->executeQuery()->fetchOne())->isIdenticalTo($expected);
                $this->array(array_column($repository->stock($table, $column, (int)$device->getID()), 'id'))->isIdenticalTo([(int)$blank->getID()]);
                if ($nullable) {
                    $this->array(array_column($repository->forDevice($table, $column, (int)$device->getID(), null, null, null), 'id'))->isIdenticalTo([(int)$nullStock->getID()]);
                }
            } finally {
                $em->clear();
            }
            foreach ([['computers_id' => -1], ['computers_id' => 0], ['opaque_parent_id' => 0], ['itemtype' => 'PluginOpaqueParent'],
                ['itemtype' => null, 'computers_id' => $asset->getID(), 'opaque_parent_id' => null]] as $invalid) {
                $this->exception(static fn () => $connection->transactional(static fn () => $connection->update($table, $invalid, ['id' => $link->getID()])))->isInstanceOf(DatabaseException::class);
            }
            // A positive orphan must fail the native FK rather than CHECK/type admission.
            $orphan = (int)$connection->fetchOne('SELECT COALESCE(MAX(id),0)+1 FROM glpi_computers');
            $this->exception(static fn () => $connection->transactional(static fn () => $connection->update($table, ['computers_id' => $orphan], ['id' => $link->getID()])))->isInstanceOf(DatabaseException::class);
            $this->exception(static fn () => $connection->transactional(static fn () => $connection->delete(Computer::getTable(), ['id' => $asset->getID()])))->isInstanceOf(DatabaseException::class);
            $this->boolean($link->update(['id' => $link->getID(), 'items_id' => $other->getID()]))->isTrue();
            $this->integer((int)$connection->fetchOne("SELECT computers_id FROM $table WHERE id=?", [$link->getID()]))->isIdenticalTo((int)$other->getID());
            $this->boolean($link->update(['id' => $link->getID(), 'itemtype' => 'Computer', 'items_id' => $asset->getID()]))->isTrue();
            // Actual configured plugin routing is retained for all families; wildcard families keep *.
            GraphicCardCustomParent::forceTable(Computer::getTable());
            $CFG_GLPI['glpitablesitemtype'][GraphicCardCustomParent::class] = Computer::getTable();
            $affinityKey = str_replace('_', '', strtolower($linkType)) . '_types';
            if (!isset($CFG_GLPI[$affinityKey])) {
                $CFG_GLPI[$affinityKey] = $CFG_GLPI['itemdevices_itemaffinity'];
            }
            $this->boolean(LegacyPlugin::registerClass(GraphicCardCustomParent::class, [$affinityKey => true, 'itemdevices_types' => true]))->isTrue();
            $GLPI_CACHE->delete('item_device_affinities');
            $this->array($linkType::getConcernedItems())->contains(GraphicCardCustomParent::class);
            $custom = $this->createItem(GraphicCardCustomParent::class, ['name' => $prefix . '-custom', 'entities_id' => 0]);
            $opaque = $this->createItem($linkType, $base + ['itemtype' => GraphicCardCustomParent::class, 'items_id' => $custom->getID()]);
            $this->variable($connection->fetchOne("SELECT computers_id FROM $table WHERE id=?", [$opaque->getID()]))->isNull();
            $this->array($opaque->getTableGroupRows($device, GraphicCardCustomParent::class))->hasSize(1);
            if (in_array(NetworkEquipment::class, $linkType::itemAffinity(), true)) {
                $network = $this->createItem(NetworkEquipment::class, ['name' => $prefix . '-network', 'entities_id' => 0]);
                $networkLink = $this->createItem($linkType, $base + ['itemtype' => NetworkEquipment::class, 'items_id' => $network->getID()]);
                $this->variable($connection->fetchOne("SELECT computers_id FROM $table WHERE id=?", [$networkLink->getID()]))->isNull();
                $this->integer((int)$networkLink->fields['opaque_parent_id'])->isIdenticalTo((int)$network->getID());
            }
            $cloned = (int)$asset->clone(['name' => $prefix . '-clone']);
            $this->integer($cloned)->isGreaterThan(0);
            $this->array($link->find(['itemtype' => 'Computer', 'items_id' => $cloned]))->hasSize($expected);
            $this->integer((int)$connection->fetchOne("SELECT COUNT(*) FROM $table WHERE itemtype='computer' AND computers_id IS NULL AND opaque_parent_id=?", [$cloned]))->isIdenticalTo($expected - 2);
            $reporting = error_reporting();
            try {
                error_reporting($reporting & ~E_USER_DEPRECATED);
                Item_Devices::cloneItem('Computer', $asset->getID(), $other->getID());
            } finally {
                error_reporting($reporting);
            }
            $this->integer((int)$connection->fetchOne("SELECT COUNT(*) FROM $table WHERE itemtype='computer' AND computers_id IS NULL AND opaque_parent_id=?", [$other->getID()]))->isIdenticalTo($expected - 2);
            $this->boolean($asset->delete(['id' => $asset->getID(), 'keep_devices' => 1], true))->isTrue();
            $this->boolean($link->getFromDB($link->getID()))->isTrue();
            $this->string($link->fields['itemtype'])->isIdenticalTo('');
            $this->integer((int)$link->fields['items_id'])->isIdenticalTo(0);
            $clonedAsset = new Computer();
            $this->boolean($clonedAsset->getFromDB($cloned))->isTrue();
            $this->boolean($clonedAsset->delete(['id' => $cloned], true))->isTrue();
            $this->array($link->find(['itemtype' => 'Computer', 'items_id' => $cloned]))->isEmpty();
            $this->boolean($opaque->getFromDB($opaque->getID()))->isTrue();
            $this->boolean($blank->delete(['id' => $blank->getID()], true))->isTrue();
            $this->boolean($blank->getFromDB($blank->getID()))->isFalse();
        } finally {
            $CFG_GLPI = $configuration;
            $_SESSION = $session;
            $tablesProperty->setValue(null, $tables);
            if ($hadAffinities) {
                $GLPI_CACHE->set('item_device_affinities', $affinities);
            } else {
                $GLPI_CACHE->delete('item_device_affinities');
            }
        }
    }

    public function testNetworkCardIncomingPortOwnershipPurgeAndKeepDevices(): void
    {
        global $DB;
        $this->login();
        $this->setEntity(0, true);
        $prefix = $this->getUniqueString();
        $device = $this->createItem(DeviceNetworkCard::class, ['designation' => $prefix, 'entities_id' => 0]);
        foreach ([false, true] as $keep) {
            $asset = $this->createItem(Computer::class, ['name' => $prefix . (int)$keep, 'entities_id' => 0]);
            $card = $this->createItem(NetworkCardLink::class, ['devicenetworkcards_id' => $device->getID(), 'itemtype' => 'Computer', 'items_id' => $asset->getID(), 'entities_id' => 0]);
            $port = new LegacyNetworkPort();
            $portId = $port->add(['name' => $prefix, 'itemtype' => 'Computer', 'items_id' => $asset->getID(),
                'entities_id' => 0, 'instantiation_type' => NetworkPortEthernet::class, 'logical_number' => 0,
                'items_devicenetworkcards_id' => $card->getID(), 'mac' => '00:00:00:00:00:01', '_create_children' => true]);
            $this->checkInput($port, $portId, ['name' => $prefix, 'itemtype' => 'Computer', 'items_id' => $asset->getID(),
                'entities_id' => 0, 'instantiation_type' => NetworkPortEthernet::class, 'logical_number' => 0]);
            $instantiation = $port->getInstantiation();
            $this->boolean($instantiation->getFromDB($port->getID()))->isTrue('The public port add must create its actual Ethernet child');
            $this->integer((int)$instantiation->fields['items_devicenetworkcards_id'])->isIdenticalTo((int)$card->getID());
            $this->string($instantiation->fields['mac'])->isIdenticalTo('00:00:00:00:00:01');
            $connection = $DB->getDoctrineConnection();
            $this->exception(static fn () => $connection->transactional(static fn () => $connection->delete($card->getTable(), ['id' => $card->getID()])))->isInstanceOf(DatabaseException::class);
            $this->boolean($asset->delete(['id' => $asset->getID(), 'keep_devices' => (int)$keep], true))->isTrue();
            $this->boolean($port->getFromDB($port->getID()))->isFalse();
            $this->boolean($instantiation->getFromDB($instantiation->getID()))->isFalse();
            $this->boolean($card->getFromDB($card->getID()))->isIdenticalTo($keep);
            if ($keep) {
                $this->string($card->fields['itemtype'])->isIdenticalTo('');
                $this->integer((int)$card->fields['items_id'])->isIdenticalTo(0);
                $this->variable($card->fields['computers_id'])->isNull();
            }
        }
    }

}

class GraphicCardPreparedOwner extends GraphicCardLink
{
    public static function getDeviceType()
    {
        return DeviceGraphicCard::class;
    }

    public ?int $preparedComputer = null;

    public static function getTable($classname = null)
    {
        return GraphicCardLink::getTable();
    }

    public function pre_updateInDB()
    {
        parent::pre_updateInDB();
        if ($this->preparedComputer !== null) {
            $this->fields['computers_id'] = $this->preparedComputer;
            $this->updates[] = 'computers_id';
        }
    }
}
