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

use Appliance;
use Appliance_Item as ApplianceItemModel;
use Computer;
use DbTestCase;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\ApplianceOwnerReadOperation;
use itsmng\Database\EntityScopeReadOperation;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ApplianceAssetRepository;
use mock\DBmysql;
use RuntimeException;

class Appliance_Item extends DbTestCase
{
    public function testGetForbiddenStandardMassiveAction()
    {
        $this->newTestedInstance();
        $this->array(
            $this->testedInstance->getForbiddenStandardMassiveAction()
        )->isIdenticalTo(['clone', 'update', 'CommonDBConnexity:unaffect', 'CommonDBConnexity:affect']);
    }

    public function testCountForAppliance()
    {
        global $DB;

        $appliance = new Appliance();

        $appliance_1 = (int)$appliance->add([
           'name'   => 'Test appliance'
        ]);
        $this->integer($appliance_1)->isGreaterThan(0);

        $appliance_2 = (int)$appliance->add([
           'name'   => 'Test appliance'
        ]);
        $this->integer($appliance_2)->isGreaterThan(0);

        $itemtypes = [
           'Computer'  => '_test_pc01',
           'Printer'   => '_test_printer_all',
           'Software'  => '_test_soft'
        ];

        foreach ($itemtypes as $itemtype => $itemname) {
            $items_id = getItemByTypeName($itemtype, $itemname, true);
            foreach ([$appliance_1, $appliance_2] as $app) {
                //no printer on appliance_2
                if ($itemtype == 'Printer' && $app == $appliance_2) {
                    continue;
                }

                $input = [
                   'appliances_id'   => $app,
                   'itemtype'        => $itemtype,
                   'items_id'        => $items_id
                ];
                $this
                   ->given($this->newTestedInstance)
                      ->then
                         ->integer($this->testedInstance->add($input))
                         ->isGreaterThan(0);
            }
        }

        $this->boolean($appliance->getFromDB($appliance_1))->isTrue();
        //not logged, no Appliances types
        $this->integer(ApplianceItemModel::countForMainItem($appliance))->isIdenticalTo(0);

        $this->login();
        $this->integer(ApplianceItemModel::countForMainItem($appliance))->isIdenticalTo(3);

        $this->boolean($appliance->getFromDB($appliance_2))->isTrue();
        $this->integer(ApplianceItemModel::countForMainItem($appliance))->isIdenticalTo(2);

        $connection = $DB->getDoctrineConnection();
        $manager = Orm::forConnection($connection);
        $repository = new ApplianceAssetRepository($manager);
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $expected = $repository->ownerCount('Computer', (int)$computer->getID(), []);
        $this->integer($expected)->isGreaterThanOrEqualTo(2);
        $probe = new ApplianceOwnerCountProbe($connection);
        $originalAdapter = $DB;
        $originalSession = $_SESSION;
        $this->mockGenerator->orphanize('__construct');
        $adapter = new DBmysql();
        $this->calling($adapter)->getDoctrineConnection = $probe;
        $this->calling($adapter)->getProvider = $originalAdapter->getProvider();
        try {
            $_SESSION['glpishowallentities'] = true;
            $DB = $adapter;
            $this->integer(ApplianceItemModel::countForItem($computer))->isIdenticalTo($expected);
            $this->array($probe->queries)->hasSize(1);
            $this->integer($probe->builders)->isIdenticalTo(1);
        } finally {
            $DB = $originalAdapter;
            $_SESSION = $originalSession;
        }
        $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $parent = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $scopedComputer = $this->createItem(Computer::class, ['name' => 'Scoped appliance subject', 'entities_id' => $child]);
        $recursiveAppliances = [];
        foreach ([[$child, 0], [$parent, 1], [0, 1], [$parent, 0]] as [$entity, $recursive]) {
            $owned = $this->createItem(Appliance::class, ['name' => 'Reverse scope ' . count($recursiveAppliances),
                'entities_id' => $entity, 'is_recursive' => $recursive]);
            $binding = new ApplianceItemModel();
            $this->integer((int)$binding->add(['appliances_id' => $owned->getID(), 'itemtype' => 'Computer',
                'items_id' => $scopedComputer->getID()]))->isGreaterThan(0);
            $recursiveAppliances[] = (int)$owned->getID();
        }
        $scopeSession = $_SESSION;
        try {
            $this->setEntity('_test_child_1', false);
            $criteria = getEntitiesRestrictCriteria('glpi_appliances', '', '', 'auto');
            $this->array($criteria)->hasSize(1);
            $this->array(reset($criteria))->hasKey('OR');
            $this->integer($repository->ownerCount('Computer', (int)$scopedComputer->getID(), $criteria))->isIdenticalTo(3);
            $probe->queries = [];
            $probe->queryBuilders = [];
            $DB = $adapter;
            $this->integer(ApplianceItemModel::countForItem($scopedComputer))->isIdenticalTo(3);
            $nativeCounts = array_filter($probe->queryBuilders, static fn ($query) =>
                str_contains(str_replace(['`', '"'], '', $query->getSQL()), 'FROM glpi_appliances r'));
            $this->array($nativeCounts)->hasSize(1);
            $DB = $originalAdapter;

            $scope = (new EntityScopeReadOperation())->restriction('glpi_appliances', '', '', 'auto');
            $this->array($scope->wrappedCriteria())->isIdenticalTo($criteria);
            $this->array($scope->entities)->isIdenticalTo([$child]);
            $this->array($scope->ancestors)->isIdenticalTo([0, $parent]);
            $scopedReader = new ApplianceOwnerReadOperation($probe);
            $boolean = Type::getType('boolean');
            try {
                $this->integer($scopedReader->ownerCount('Computer', (int)$scopedComputer->getID(), $criteria, $scope))->isIdenticalTo(3);
                $connection->update('glpi_appliances', ['is_recursive' => false], ['id' => $recursiveAppliances[1]], ['is_recursive' => 'boolean', 'id' => 'bigint']);
                $this->integer($scopedReader->ownerCount('Computer', (int)$scopedComputer->getID(), $criteria, $scope))->isIdenticalTo(2);
                $this->integer($scopedReader->ownerCount('Computer', (int)$scopedComputer->getID(), $criteria, $scope))
                    ->isIdenticalTo($repository->ownerCount('Computer', (int)$scopedComputer->getID(), $criteria));
                $connection->update('glpi_appliances', ['is_recursive' => true], ['id' => $recursiveAppliances[1]], ['is_recursive' => 'boolean', 'id' => 'bigint']);
                Type::overrideType('boolean', new class () extends BooleanType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'CASE WHEN ' . $sqlExpr . ' = TRUE THEN FALSE ELSE TRUE END';
                    }
                });
                $this->integer($scopedReader->ownerCount('Computer', (int)$scopedComputer->getID(), $criteria, $scope))->isIdenticalTo(2);
                $this->integer($repository->ownerCount('Computer', (int)$scopedComputer->getID(), $criteria))->isIdenticalTo(2);
            } finally {
                Type::overrideType('boolean', $boolean);
                $scopedReader->close();
            }
            $rootScopes = [];
            foreach ([0, [0]] as $rootSelection) {
                $rootScopes[] = (new EntityScopeReadOperation())->restriction('glpi_appliances', '', $rootSelection, 'auto');
            }
            $typedReader = new ApplianceOwnerReadOperation($probe);
            $integer = Type::getType('integer');
            try {
                foreach ($rootScopes as $rootScope) {
                    $probe->queries = [];
                    $this->integer($typedReader->ownerCount('Computer', (int)$scopedComputer->getID(), $rootScope->wrappedCriteria(), $rootScope))->isIdenticalTo(1);
                    $this->array($probe->queries[0]['types'])->isIdenticalTo(['bigint', 'integer']);
                    $sql = str_replace(['`', '"'], '', $probe->queries[0]['sql']);
                    $this->string($sql)->contains($rootScope->entityList ? 'r.entities_id IN (?)' : 'r.entities_id = ?');
                }
                Type::overrideType('integer', new class ($parent) extends IntegerType {
                    public function __construct(private int $offset)
                    {
                    }
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return '(' . $sqlExpr . ' + ' . $this->offset . ')';
                    }
                });
                foreach ($rootScopes as $rootScope) {
                    $this->integer($typedReader->ownerCount('Computer', (int)$scopedComputer->getID(), $rootScope->wrappedCriteria(), $rootScope))->isIdenticalTo(2);
                    $this->integer($repository->ownerCount('Computer', (int)$scopedComputer->getID(), $rootScope->wrappedCriteria()))->isIdenticalTo(2);
                }
            } finally {
                Type::overrideType('integer', $integer);
                $typedReader->close();
            }
            $callbackItem = new class () extends Computer {
                public $readCallback;
                public static function getType()
                {
                    return 'Computer';
                }
                public function getID()
                {
                    ($this->readCallback)();
                    return parent::getID();
                }
            };
            $callbackItem->fields = $scopedComputer->fields;
            $callbackItem->readCallback = static function () use ($originalAdapter): void {
                $GLOBALS['DB'] = $originalAdapter;
                $_SESSION['glpiactiveentities'] = [];
            };
            $DB = $adapter;
            $probe->queryBuilders = [];
            $this->integer(ApplianceItemModel::countForItem($callbackItem))->isIdenticalTo(3);
            $this->object($DB)->isIdenticalTo($originalAdapter);
            $this->array(array_filter($probe->queryBuilders, static fn ($query) =>
                str_contains(str_replace(['`', '"'], '', $query->getSQL()), 'FROM glpi_appliances r')))->hasSize(1);
            $this->integer(ApplianceItemModel::countForItem($scopedComputer))->isIdenticalTo(0);
        } finally {
            $DB = $originalAdapter;
            $_SESSION = $scopeSession;
        }

        $reader = new ApplianceOwnerReadOperation($probe);
        try {
            foreach ([[], ['glpi_appliances.entities_id' => 0], ['glpi_appliances.entities_id' => [0, '1']],
                ['glpi_appliances.entities_id' => []], ['OR' => ['glpi_appliances.entities_id' => 0, 'glpi_appliances.is_recursive' => true]]] as $criteria) {
                if ($criteria === ['glpi_appliances.entities_id' => []]) {
                    $this->exception(static fn () => $reader->ownerCount('Computer', (int)$computer->getID(), $criteria))->isInstanceOf(RuntimeException::class);
                    continue;
                }
                $before = $probe->builders;
                $this->integer($reader->ownerCount('Computer', (int)$computer->getID(), $criteria))
                    ->isIdenticalTo($repository->ownerCount('Computer', (int)$computer->getID(), $criteria));
                $this->integer($probe->builders - $before)->isIdenticalTo($criteria === [] ? 1 : 0);
            }
            $this->integer($reader->ownerCount('Computer', 0, []))->isIdenticalTo(0);
            $this->integer($reader->ownerCount('UnknownApplianceAsset', (int)$computer->getID(), []))->isIdenticalTo(0);
            $originalType = Type::getType(Types::BIGINT);
            try {
                Type::overrideType(Types::BIGINT, new class () extends BigIntType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'CASE WHEN ' . $sqlExpr . ' = -1 THEN -1 ELSE -1 END';
                    }
                });
                $this->integer($reader->ownerCount('Computer', (int)$computer->getID(), []))->isIdenticalTo(0);
                $this->integer($repository->ownerCount('Computer', (int)$computer->getID(), []))->isIdenticalTo(0);
            } finally {
                Type::overrideType(Types::BIGINT, $originalType);
            }
        } finally {
            $reader->close();
            $manager->close();
        }

        $this->boolean($appliance->getFromDB($appliance_1))->isTrue();
        $this->boolean($appliance->delete(['id' => $appliance_1], true))->isTrue();

        $this->boolean($appliance->getFromDB($appliance_2))->isTrue();
        $this->boolean($appliance->delete(['id' => $appliance_2], true))->isTrue();

        $iterator = $DB->request([
           'FROM'   => ApplianceItemModel::getTable(),
           'WHERE'  => ['appliances_id' => [$appliance_1, $appliance_2]]
        ]);
        $this->integer(count($iterator))->isIdenticalTo(0);
    }
}

/** Observe the selected transaction without a second socket. */
class ApplianceOwnerCountProbe extends Connection
{
    public int $builders = 0;
    public array $queries = [];
    public array $queryBuilders = [];

    public function __construct(private readonly Connection $selected)
    {
        parent::__construct($selected->getParams(), $selected->getDriver(), $selected->getConfiguration());
    }

    public function isTransactionActive(): bool
    {
        return $this->selected->isTransactionActive();
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        return $this->selected->getDatabasePlatform();
    }

    public function createQueryBuilder(): QueryBuilder
    {
        ++$this->builders;
        $query = parent::createQueryBuilder();
        $this->queryBuilders[] = $query;
        return $query;
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
        return $this->selected->executeQuery($sql, $params, $types, $qcp);
    }
}
