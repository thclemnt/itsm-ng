<?php

/**
 * ---------------------------------------------------------------------
 * ITSM-NG
 * Copyright (C) 2022 ITSM-NG and contributors.
 *
 * https://www.itsm-ng.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of ITSM-NG.
 *
 * ITSM-NG is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * ITSM-NG is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ITSM-NG. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

namespace tests\units;

use DbTestCase;
use Doctrine\Common\EventManager;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Entity as LegacyEntity;
use Doctrine\ORM\EntityManager;
use Location as LegacyLocation;
use RuntimeException;
use Transfer;
use itsmng\Database\Entity\Location as LocationRecord;
use Netpoint as LegacyNetpoint;
use itsmng\Database\Entity\Netpoint as OutletRecord;
use itsmng\Database\Orm;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\UnsupportedCriteria;
use mock\DBmysql as OutletPageAdapterProbe;
use tests\fixtures\ScalarReadProbe;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

class Location extends DbTestCase
{
    public function testLiteralTreeImportLookupAndDerivedCachesKeepRealValues(): void
    {
        global $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $this->setEntity('_test_root_entity', true);
            $parentEntity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $entity = $this->createItem(LegacyEntity::class, ['name' => $this->getUniqueString(), 'entities_id' => $parentEntity]);
            $other = $this->createItem(LegacyEntity::class, ['name' => $this->getUniqueString(), 'entities_id' => $parentEntity]);
            $location = new LegacyLocation();
            $connection = $DB->getDoctrineConnection();
            $decoy = $this->createItem(LegacyLocation::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity->getID()]);
            $connection->update('glpi_locations', ['name' => null, 'completename' => null], ['id' => $decoy->getID()]);
            $root = (int)$location->import(['name' => 'NULL', 'entities_id' => $entity->getID()]);
            $this->integer($root)->isGreaterThan(0);
            $child = (int)$location->import(['completename' => 'NULL > null', 'entities_id' => $entity->getID()]);
            $this->integer($child)->isGreaterThan(0);
            $before = $connection->fetchOne('SELECT COUNT(*) FROM glpi_locations WHERE entities_id=?', [$entity->getID()]);
            $this->integer((int)$location->import(['completename' => ' NULL > null ', 'entities_id' => $entity->getID()]))
                ->isIdenticalTo($child);
            $this->variable($connection->fetchOne('SELECT COUNT(*) FROM glpi_locations WHERE entities_id=?', [$entity->getID()]))
                ->isIdenticalTo($before);
            $this->array($connection->fetchAssociative('SELECT name, completename FROM glpi_locations WHERE id=?', [$root]))
                ->isIdenticalTo(['name' => 'NULL', 'completename' => 'NULL']);
            $this->array($connection->fetchAssociative('SELECT name, completename FROM glpi_locations WHERE id=?', [$child]))
                ->isIdenticalTo(['name' => 'null', 'completename' => 'NULL > null']);
            $params = ['completename' => ' NULL > null ', 'entities_id' => $entity->getID()];
            $this->integer((int)$location->findID($params))->isIdenticalTo($child);
            $this->string($params['completename'])->isIdenticalTo('NULL > null');
            $quoted = "O'Reilly\\north";
            $quoteId = (int)$location->import(['name' => addslashes($quoted), 'entities_id' => $entity->getID()]);
            $this->integer($quoteId)->isGreaterThan(0);
            $params = ['name' => addslashes($quoted), 'entities_id' => $entity->getID()];
            $this->integer((int)$location->findID($params))->isIdenticalTo($quoteId);
            $this->string($connection->fetchOne('SELECT name FROM glpi_locations WHERE id=?', [$quoteId]))->isIdenticalTo($quoted);
            $params = ['name' => 'null', 'locations_id' => $quoteId, 'entities_id' => $entity->getID()];
            $this->integer((int)$location->findID($params))->isIdenticalTo(-1);
            $params['locations_id'] = $root;
            $this->integer((int)$location->findID($params))->isIdenticalTo($child);
            $target = (int)$location->import(['name' => 'NULL', 'entities_id' => $other->getID()]);
            $this->integer($target)->isGreaterThan(0);
            $params = ['completename' => 'NULL', 'entities_id' => $other->getID()];
            $this->integer((int)$location->findID($params))->isIdenticalTo($target);
            $transfer = new Transfer();
            $transfer->to = (int)$other->getID();
            $this->integer((int)$transfer->transferDropdownLocation($root))->isIdenticalTo($target);
            // Generic query null continues to mean SQL NULL, unlike typed import identity.
            $this->array(array_keys($location->find(['name' => null, 'entities_id' => $entity->getID()], ['id'])))
                ->isIdenticalTo([(int)$decoy->getID()]);
            $connection->update('glpi_locations', ['ancestors_cache' => '{"stale":1}'], ['id' => $child]);
            $location->regenerateTreeUnderID(0, true, true);
            $this->string($connection->fetchOne('SELECT completename FROM glpi_locations WHERE id=?', [$root]))->isIdenticalTo('NULL');
            $this->variable($connection->fetchOne('SELECT ancestors_cache FROM glpi_locations WHERE id=?', [$child]))->isNull();
            $this->boolean($location->getFromDB($child))->isTrue();
            $connection->update('glpi_locations', ['sons_cache' => '{"stale":1}'], ['id' => $root]);
            $this->boolean($location->update(['id' => $child, 'locations_id' => $quoteId]))->isTrue();
            $this->variable($connection->fetchOne('SELECT sons_cache FROM glpi_locations WHERE id=?', [$root]))->isNull();
            $this->string($connection->fetchOne('SELECT completename FROM glpi_locations WHERE id=?', [$child]))
                ->isIdenticalTo($quoted . ' > null');
            $owner = Orm::create($DB);
            $dirty = $owner->find(LocationRecord::class, $root);
            $dirty->name = 'Unflushed tree name';
            $params = ['name' => 'NULL', 'entities_id' => $entity->getID()];
            $this->integer((int)$location->findID($params))->isIdenticalTo($root);
            $this->boolean($owner->contains($dirty))->isTrue();
            $this->string($dirty->name)->isIdenticalTo('Unflushed tree name');
            Orm::read($DB, function (EntityManager $outer) use ($location, $root, $params): void {
                $dirty = $outer->find(LocationRecord::class, $root);
                $dirty->completename = 'Unflushed outer path';
                $this->integer((int)$location->findID($params))->isIdenticalTo($root);
                $this->boolean($outer->contains($dirty))->isTrue();
                $this->string($dirty->completename)->isIdenticalTo('Unflushed outer path');
            });
            $owner->clear();
        } finally {
            $_SESSION = $session;
        }
    }

    public function testTreeIdentityHookKeepsCustomTableRouteAndLegacyOpaqueFind(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $record = $this->createItem(LegacyLocation::class, ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
            $connection = $DB->getDoctrineConnection();
            $observer = new class () {
                public array $trace = [];
                public int $clears = 0;
                public int $beforeIdentity = -1;
                public array $loadedManagers = [];
                public array $clearedManagers = [];
                public ?RuntimeException $loadFailure = null;
                public ?RuntimeException $clearFailure = null;

                public function postLoad(PostLoadEventArgs $event): void
                {
                    if ($event->getObject() instanceof LocationRecord) {
                        $this->trace[] = 'loaded';
                        $this->loadedManagers[] = $event->getObjectManager();
                        if ($this->loadFailure !== null) {
                            throw $this->loadFailure;
                        }
                    }
                }

                public function onClear(OnClearEventArgs $event): void
                {
                    ++$this->clears;
                    $manager = $event->getObjectManager();
                    $this->clearedManagers[] = $manager;
                    if (in_array($manager, $this->loadedManagers, true)) {
                        $this->trace[] = 'cleared';
                        if ($this->clearFailure !== null) {
                            throw $this->clearFailure;
                        }
                    }
                }
            };
            $events = new EventManager();
            $events->addEventListener(['postLoad', 'onClear'], $observer);
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
            };
            $other = new ScalarReadProbe($connection);
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new OutletPageAdapterProbe();
            $alternate = new OutletPageAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = $selected;
            $this->calling($alternate)->getDoctrineConnection = $other;
            $this->calling($adapter)->getProvider = $original->getProvider();
            $this->calling($alternate)->getProvider = $original->getProvider();
            $model = new class () extends LegacyLocation {
                public static bool $identity = false;
                public static object $observer;
                public static object $alternate;
                public static ?RuntimeException $failure = null;
                public static ?string $changedTable = null;
                public array $opaque = [];

                public static function getType()
                {
                    return LegacyLocation::getType();
                }

                public static function getTable($classname = null)
                {
                    if (self::$identity) {
                        self::$observer->trace[] = 'table';
                        self::$observer->beforeIdentity = self::$observer->clears;
                        if (self::$failure !== null) {
                            throw self::$failure;
                        }
                        $GLOBALS['DB'] = self::$alternate;
                        if (self::$changedTable !== null) {
                            return self::$changedTable;
                        }
                    }
                    return LegacyLocation::getTable($classname);
                }

                protected function findTreeIdentity(array $criteria, string $column, string $table): array
                {
                    self::$observer->trace[] = 'identity';
                    self::$identity = true;
                    try {
                        return parent::findTreeIdentity($criteria, $column, $table);
                    } finally {
                        self::$identity = false;
                    }
                }

                public function find($condition = [], $order = [], $limit = null)
                {
                    $this->opaque[] = [$condition, $order, $limit];
                    return [42 => ['id' => 42]];
                }
            };
            $model::$observer = $observer;
            $model::$alternate = $alternate;
            $DB = $adapter;
            $params = ['name' => $record->getField('name'), 'entities_id' => $entity];
            $this->integer((int)$model->findID($params))->isIdenticalTo((int)$record->getID());
            $at = array_search('identity', $observer->trace, true);
            $this->array(array_slice($observer->trace, $at))->isIdenticalTo(['identity', 'table', 'constructed', 'loaded', 'cleared']);
            $this->integer($observer->clears)->isIdenticalTo($observer->beforeIdentity + 1);
            $this->object(end($observer->clearedManagers))->isIdenticalTo(end($observer->loadedManagers));
            $this->array($other->queries)->isEmpty();
            $this->array($model->opaque)->isEmpty();
            foreach (['glpi_entities', 'unmapped_tree_identity'] as $changedTable) {
                $DB = $adapter;
                $model::$changedTable = $changedTable;
                $before = count($observer->trace);
                $this->exception(function () use ($model, $params): void {
                    $model->findID($params);
                })->isInstanceOf(UnsupportedCriteria::class)
                    ->hasMessage('Tree identity requires a stable mapped table; override findTreeIdentity for dynamic routes.');
                $this->array(array_slice($observer->trace, $before))->isIdenticalTo(['identity', 'table']);
                $this->array($model->opaque)->isEmpty();
            }
            $model::$changedTable = null;
            $DB = $adapter;
            $model::$failure = new RuntimeException('Tree table callback failure');
            $this->exception(function () use ($model, $params): void {
                $model->findID($params);
            })->isIdenticalTo($model::$failure);
            $model::$failure = null;
            $this->integer((int)$model->findID($params))->isIdenticalTo((int)$record->getID());
            foreach (['read', 'cleanup', 'both'] as $failureKind) {
                $DB = $adapter;
                $observer->loadFailure = $failureKind === 'cleanup' ? null : new RuntimeException('Tree read callback failure');
                $observer->clearFailure = $failureKind === 'read' ? null : new RuntimeException('Tree cleanup callback failure');
                $before = count($observer->trace);
                $failure = null;
                try {
                    $model->findID($params);
                } catch (RuntimeException $error) {
                    $failure = $error;
                }
                if ($failureKind === 'both') {
                    $this->object($failure)->isInstanceOf(MutationCleanupFailure::class);
                    $this->object($failure->primary)->isIdenticalTo($observer->loadFailure);
                    $this->object($failure->cleanup)->isIdenticalTo($observer->clearFailure);
                } else {
                    $this->object($failure)->isIdenticalTo($observer->loadFailure ?? $observer->clearFailure);
                }
                $this->array(array_slice($observer->trace, $before))
                    ->isIdenticalTo(['identity', 'table', 'constructed', 'loaded', 'cleared']);
                $this->integer($observer->clears)->isIdenticalTo($observer->beforeIdentity + 1);
                $this->object(end($observer->clearedManagers))->isIdenticalTo(end($observer->loadedManagers));
                $failedReader = end($observer->loadedManagers);
                $observer->loadFailure = null;
                $observer->clearFailure = null;
                $DB = $adapter;
                $this->integer((int)$model->findID($params))->isIdenticalTo((int)$record->getID());
                $this->object(end($observer->loadedManagers))->isNotIdenticalTo($failedReader);
                $this->integer($observer->clears)->isIdenticalTo($observer->beforeIdentity + 1);
            }
            $opaque = ['name' => ['LIKE', 'NULL'], 'entities_id' => $entity];
            $this->integer((int)$model->findID($opaque))->isIdenticalTo(42);
            $this->array($model->opaque)->hasSize(1);
        } finally {
            $DB = $original;
            $_SESSION = $session;
        }
    }

    public function testNetworkOutletTabKeepsPageValuesAndLiveOwners(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        $get = $_GET;
        $writer = null;
        try {
            $this->login();
            $entity = getItemByTypeName('Entity', '_test_root_entity', true);
            $location = $this->createItem(LegacyLocation::class, ['name' => 'outlet-location-' . $this->getUniqueString(), 'entities_id' => $entity]);
            $other = $this->createItem(LegacyLocation::class, ['name' => 'other-outlet-location-' . $this->getUniqueString(), 'entities_id' => $entity]);
            $first = $this->createItem(LegacyNetpoint::class, ['name' => 'A-outlet-page', 'comment' => 'first outlet comment', 'entities_id' => $entity, 'locations_id' => $location->getID()]);
            $second = $this->createItem(LegacyNetpoint::class, ['name' => 'B-outlet-page', 'comment' => 'second outlet comment', 'entities_id' => $entity, 'locations_id' => $location->getID()]);
            $third = $this->createItem(LegacyNetpoint::class, ['name' => 'B-outlet-page', 'comment' => 'third outlet comment', 'entities_id' => $entity, 'locations_id' => $location->getID()]);
            $this->createItem(LegacyNetpoint::class, ['name' => 'other location outlet', 'entities_id' => $entity, 'locations_id' => $other->getID()]);
            $_SESSION['glpilist_limit'] = 2;
            $_GET['start'] = 1;
            $this->output(static fn () => LegacyNetpoint::displayTabContentForItem($location))->contains('second outlet comment')->contains('third outlet comment')->notContains('first outlet comment')->notContains('other location outlet');
            $ids = array_map('intval', $_SESSION['glpilistitems']['Netpoint']);
            sort($ids);
            $expected = [(int)$second->getID(), (int)$third->getID()];
            sort($expected);
            $this->array($ids)->isIdenticalTo($expected);
            $writer = Orm::create($DB);
            $live = $writer->find(OutletRecord::class, (int)$first->getID());
            $live->name = 'Independent pending outlet';
            $this->boolean($first->update(['id' => $first->getID(), 'name' => 'Z-current-outlet']))->isTrue();
            $original->getDoctrineConnection()->update('glpi_netpoints', ['comment' => null], ['id' => $second->getID()]);
            $_SESSION['glpilist_limit'] = 0;
            $_GET['start'] = 20;
            $this->output(static fn () => LegacyNetpoint::displayTabContentForItem($location))->contains('Z-current-outlet')->contains('third outlet comment')->notContains('A-outlet-page')->notContains('second outlet comment')->notContains('Independent pending outlet');
            $this->array($_SESSION['glpilistitems']['Netpoint'])->hasSize(3);
            Orm::read(
                $DB,
                function (EntityManager $outer) use ($location, $first): void {
                    $owned = $outer->find(OutletRecord::class, (int)$first->getID());
                    $owned->name = 'Outer pending outlet';
                    $this->output(static fn () => LegacyNetpoint::displayTabContentForItem($location))->contains('Z-current-outlet')->notContains('Outer pending outlet');
                    $this->boolean($outer->contains($owned))->isTrue();
                    $this->string($owned->name)->isIdenticalTo('Outer pending outlet');
                }
            );
            $probe = new ScalarReadProbe($original->getDoctrineConnection());
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new OutletPageAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = $probe;
            $this->calling($adapter)->getProvider = $original->getProvider();
            $DB = $adapter;
            $this->output(static fn () => LegacyNetpoint::displayTabContentForItem($location))->contains('Z-current-outlet')->notContains('other location outlet');
            $pageQueries = array_values(array_filter($probe->queries, static fn (array $query): bool => isset($query['params']['location'])));
            $this->array($pageQueries)->hasSize(1);
            $this->integer((int)$pageQueries[0]['params']['location'])->isIdenticalTo((int)$location->getID());
            $this->boolean($writer->contains($live))->isTrue();
            $this->string($live->name)->isIdenticalTo('Independent pending outlet');
        } finally {
            $DB = $original;
            $writer?->clear();
            $_SESSION = $session;
            $_GET = $get;
        }
    }

    public function testImportExternal()
    {
        $locations_id = \Dropdown::importExternal(
            'Location',
            'testImportExternal_1',
            getItemByTypeName('Entity', '_test_root_entity', true)
        );

        $this->integer((int)$locations_id)->isGreaterThan(0);

        $location = new \Location();
        $this->boolean($location->getFromDB($locations_id))->isTrue();
        $this->string($location->fields['name'])->isIdenticalTo('testImportExternal_1');
    }

    public function testFindIDByName()
    {
        $entities_id = getItemByTypeName('Entity', '_test_root_entity', true);

        $location = new \Location();
        $location_id = $location->add([
           'name'        => 'testFindIDByName_1',
           'entities_id' => $entities_id,
        ]);
        $this->integer((int)$location_id)->isGreaterThan(0);

        $params = [
           'name'        => 'testFindIDByName_1',
           'entities_id' => $entities_id,
        ];
        $this->integer((int)$location->findID($params))->isIdenticalTo((int)$location_id);

        $location_id_2 = $location->add([
           'locations_id' => $location_id,
           'name'         => 'testFindIDByName_2',
           'entities_id'  => $entities_id,
        ]);
        $this->integer((int)$location_id_2)->isGreaterThan(0);

        $params = [
           'name'         => 'testFindIDByName_2',
           'locations_id' => $location_id,
           'entities_id'  => $entities_id,
        ];
        $this->integer((int)$location->findID($params))->isIdenticalTo((int)$location_id_2);

        $params = [
           'name'        => 'testFindIDByName_2',
           'entities_id' => $entities_id,
        ];
        $this->integer((int)$location->findID($params))->isIdenticalTo(-1);
    }

    public function testFindIDByCompleteName()
    {
        $entities_id = getItemByTypeName('Entity', '_test_root_entity', true);

        $location = new \Location();
        $location_id = $location->add([
           'name'        => 'testFindIDByCompleteName_1',
           'entities_id' => $entities_id,
        ]);
        $this->integer((int)$location_id)->isGreaterThan(0);

        $params = [
           'completename' => 'testFindIDByCompleteName_1',
           'entities_id'  => $entities_id,
        ];
        $this->integer((int)$location->findID($params))->isIdenticalTo((int)$location_id);

        $location_id_2 = $location->add([
           'locations_id' => $location_id,
           'name'         => 'testFindIDByCompleteName_2',
           'entities_id'  => $entities_id,
        ]);
        $this->integer((int)$location_id_2)->isGreaterThan(0);

        $params = [
           'completename' => 'testFindIDByCompleteName_1 > testFindIDByCompleteName_2',
           'entities_id'  => $entities_id,
        ];
        $this->integer((int)$location->findID($params))->isIdenticalTo((int)$location_id_2);
    }

    public function testUnicity()
    {
        $location_1 = new \Location();
        $location_1_id = $location_1->add([
           'name' => 'Unique location',
        ]);
        $this->integer((int)$location_1_id)->isGreaterThan(0);
        $this->boolean($location_1->getFromDB($location_1_id))->isTrue();
        $this->string($location_1->fields['completename'])->isIdenticalTo('Unique location');

        $location_2 = new \Location();
        $location_2_id = $location_2->add([
           'name' => 'Non unique location',
        ]);
        $this->integer((int)$location_2_id)->isGreaterThan(0);
        $this->boolean($location_2->getFromDB($location_2_id))->isTrue();
        $this->string($location_2->fields['completename'])->isIdenticalTo('Non unique location');

        $this->exception(
            function () use ($location_2, $location_2_id) {
                $location_2->update([
                   'id'   => $location_2_id,
                   'name' => 'Unique location',
                ]);
            }
        )
           ->isInstanceOf(UniqueConstraintViolationException::class);

        $this->boolean($location_2->getFromDB($location_2_id))->isTrue();
        $this->string($location_2->fields['name'])->isIdenticalTo('Non unique location');
        $this->string($location_2->fields['completename'])->isIdenticalTo('Non unique location');
    }

    protected function importProvider()
    {
        $root_entity_id = getItemByTypeName('Entity', '_test_root_entity', true);
        $sub_entity_id  = getItemByTypeName('Entity', '_test_child_1', true);

        return [
           [
              'input'    => [
                 'entities_id' => $root_entity_id,
                 'name'        => 'Import by name',
              ],
              'imported' => [
                 [
                    'entities_id' => $root_entity_id,
                    'name'        => 'Import by name',
                 ],
              ],
           ],
           [
              'input'    => [
                 'entities_id' => $sub_entity_id,
                 'name'        => 'Import by name',
              ],
              'imported' => [
                 [
                    'entities_id' => $sub_entity_id,
                    'name'        => 'Import by name',
                 ],
              ],
           ],
        ];
    }

    /**
     * @dataProvider importProvider
     */
    public function testImport(array $input, array $imported)
    {
        $instance = new \Location();
        $count_before_import = countElementsInTable(\Location::getTable());

        $this->integer((int)$instance->import($input))->isGreaterThan(0);
        $this->integer(countElementsInTable(\Location::getTable()) - $count_before_import)
           ->isIdenticalTo(count($imported));

        foreach ($imported as $location_data) {
            $this->integer(countElementsInTable(\Location::getTable(), $location_data))->isIdenticalTo(1);
        }
    }

    public function testImportTree()
    {
        $instance = new \Location();
        $imported_id = $instance->import([
           'entities_id'  => 0,
           'completename' => 'location 1 > sub location A',
        ]);

        $this->integer((int)$imported_id)->isGreaterThan(0);

        $imported = new \Location();
        $this->boolean($imported->getFromDB($imported_id))->isTrue();
        $this->string($imported->fields['name'])->isIdenticalTo('sub location A');
        $this->integer((int)$imported->fields['locations_id'])->isGreaterThan(0);

        $imported_parent = new \Location();
        $this->boolean($imported_parent->getFromDB($imported->fields['locations_id']))->isTrue();
        $this->string($imported_parent->fields['name'])->isIdenticalTo('location 1');
        $this->integer((int)$imported_parent->fields['locations_id'])->isIdenticalTo(0);

        $imported_id = $instance->import([
           'entities_id'  => 0,
           'completename' => '_location01 > sub location B',
        ]);

        $this->integer((int)$imported_id)->isGreaterThan(0);

        $imported = new \Location();
        $this->boolean($imported->getFromDB($imported_id))->isTrue();
        $this->string($imported->fields['name'])->isIdenticalTo('sub location B');
        $this->integer((int)$imported->fields['locations_id'])
           ->isIdenticalTo(getItemByTypeName('Location', '_location01', true));
    }

    public function testImportSeparator()
    {
        $instance = new \Location();
        $imported_id = $instance->import([
           'entities_id'  => 0,
           'completename' => '_location01 > _sublocation01',
        ]);

        $this->integer((int)$imported_id)->isIdenticalTo(getItemByTypeName('Location', '_sublocation01', true));

        $imported_id = $instance->import([
           'entities_id'  => 0,
           'completename' => '_location02>_sublocation02',
        ]);

        $this->integer((int)$imported_id)->isGreaterThan(0);

        $location = new \Location();
        $this->boolean($location->getFromDB($imported_id))->isTrue();
        $this->string($location->fields['name'])->isIdenticalTo('_sublocation02');
        $this->integer((int)$location->fields['locations_id'])
           ->isIdenticalTo(getItemByTypeName('Location', '_location02', true));
    }

    public function testImportParentVisibleEntity()
    {
        $instance = new \Location();

        $root_entity_id = getItemByTypeName('Entity', '_test_root_entity', true);
        $sub_entity_id  = getItemByTypeName('Entity', '_test_child_1', true);

        $parent_location = $this->createItem(
            \Location::class,
            [
               'entities_id'  => $root_entity_id,
               'is_recursive' => 1,
               'name'         => 'Parent location',
            ]
        );

        $imported_id = $instance->import([
           'entities_id'  => $sub_entity_id,
           'completename' => 'Parent location > Child name',
        ]);

        $this->integer((int)$imported_id)->isGreaterThan(0);

        $imported = new \Location();
        $this->boolean($imported->getFromDB($imported_id))->isTrue();
        $this->string($imported->fields['name'])->isIdenticalTo('Child name');
        $this->integer((int)$imported->fields['entities_id'])->isIdenticalTo($sub_entity_id);
        $this->integer((int)$imported->fields['locations_id'])->isIdenticalTo($parent_location->getID());
    }

    public function testImportParentNotVisibleEntity()
    {
        $instance = new \Location();

        $root_entity_id = getItemByTypeName('Entity', '_test_root_entity', true);
        $sub_entity_id  = getItemByTypeName('Entity', '_test_child_1', true);

        $root_level_1 = $this->createItem(
            \Location::class,
            [
               'entities_id'  => $root_entity_id,
               'is_recursive' => 0,
               'name'         => 'Location level 1',
            ]
        );
        $root_level_2 = $this->createItem(
            \Location::class,
            [
               'entities_id'  => $root_entity_id,
               'is_recursive' => 0,
               'name'         => 'Location level 2',
            ]
        );

        $imported_id = $instance->import([
           'entities_id'  => $sub_entity_id,
           'completename' => 'Location level 1 > Location level 2 > Location level 3',
        ]);

        $this->integer((int)$imported_id)->isGreaterThan(0);

        $level_3 = new \Location();
        $this->boolean($level_3->getFromDB($imported_id))->isTrue();
        $this->string($level_3->fields['name'])->isIdenticalTo('Location level 3');
        $this->integer((int)$level_3->fields['entities_id'])->isIdenticalTo($sub_entity_id);

        $level_2 = new \Location();
        $this->boolean($level_2->getFromDB($level_3->fields['locations_id']))->isTrue();
        $this->string($level_2->fields['name'])->isIdenticalTo('Location level 2');
        $this->integer((int)$level_2->fields['entities_id'])->isIdenticalTo($sub_entity_id);
        $this->integer((int)$level_2->getID())->isNotIdenticalTo($root_level_2->getID());

        $level_1 = new \Location();
        $this->boolean($level_1->getFromDB($level_2->fields['locations_id']))->isTrue();
        $this->string($level_1->fields['name'])->isIdenticalTo('Location level 1');
        $this->integer((int)$level_1->fields['entities_id'])->isIdenticalTo($sub_entity_id);
        $this->integer((int)$level_1->getID())->isNotIdenticalTo($root_level_1->getID());
        $this->integer((int)$level_1->fields['locations_id'])->isIdenticalTo(0);
    }

    public function testMaybeLocated()
    {
        global $CFG_GLPI;

        foreach ($CFG_GLPI['location_types'] as $type) {
            $item = new $type();
            $this->boolean($item->maybeLocated())->isTrue($type . ' cannot be located!');
        }
    }

    public function testTabs()
    {
        $this->login();

        $location = $this->createItem(\Location::class, [
           'name'        => 'testTabs',
           'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
        ]);

        $tabs = $location->defineTabs();

        $this->array($tabs)->hasKeys([
           'Location$main',
           'Log$1',
           'Netpoint$1',
           'Document_Item$1',
           'Location$1',
           'Location$2',
        ]);
        $this->string($tabs[\Location::class . '$1'])->contains('Locations');
        $this->string($tabs[\Location::class . '$2'])->contains('Items');
    }
}
