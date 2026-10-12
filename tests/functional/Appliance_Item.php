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
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Computer as ComputerEntity;
use itsmng\Database\EntityScopeReadOperation;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ApplianceAssetRepository;
use mock\DBmysql;

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
        $originalAdapter = $DB;
        $scopeSession = $_SESSION;
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
        try {
            $this->setEntity('_test_child_1', false);
            $this->integer(ApplianceItemModel::countForItem($scopedComputer))->isIdenticalTo(3);
            try {
                $connection->update('glpi_appliances', ['is_recursive' => false], ['id' => $recursiveAppliances[1]], ['is_recursive' => Types::BOOLEAN]);
                $this->integer(ApplianceItemModel::countForItem($scopedComputer))->isIdenticalTo(2);
            } finally {
                $connection->update('glpi_appliances', ['is_recursive' => true], ['id' => $recursiveAppliances[1]], ['is_recursive' => Types::BOOLEAN]);
            }
            $this->integer(ApplianceItemModel::countForItem($scopedComputer))->isIdenticalTo(3);
            $nonCronSession = $_SESSION;
            $_SESSION['glpicronuserrunning'] = true;
            $this->integer(ApplianceItemModel::countForItem($scopedComputer))->isIdenticalTo(4, 'Cron includes the nonrecursive parent owner');
            $_SESSION = $nonCronSession;

            // A caller-selected connection must perform the scoped count itself.
            $probe = new ApplianceOwnerCountProbe($connection);
            $this->mockGenerator->orphanize('__construct');
            $adapter = new DBmysql();
            $this->calling($adapter)->getDoctrineConnection = $probe;
            $this->calling($adapter)->getProvider = $originalAdapter->getProvider();
            $DB = $adapter;
            $this->integer(ApplianceItemModel::countForItem($scopedComputer))->isIdenticalTo(3);
            $this->array(array_filter($probe->queries, static fn (array $query): bool =>
                str_contains($query['sql'], 'COUNT(') && str_contains($query['sql'], 'glpi_appliances')))->hasSize(1);
            $DB = $originalAdapter;

            // The selected route still applies custom DBAL SQL conversions to bound criteria.
            $boolean = Type::getType(Types::BOOLEAN);
            try {
                Type::overrideType(Types::BOOLEAN, new class () extends BooleanType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'CASE WHEN ' . $sqlExpr . ' = TRUE THEN FALSE ELSE TRUE END';
                    }
                });
                $DB = $adapter;
                $this->integer(ApplianceItemModel::countForItem($scopedComputer))->isIdenticalTo(2);
            } finally {
                Type::overrideType(Types::BOOLEAN, $boolean);
                $DB = $originalAdapter;
            }
            $rootScopes = [];
            foreach ([0, [0]] as $rootSelection) {
                $rootScopes[] = (new EntityScopeReadOperation())->restriction('glpi_appliances', '', $rootSelection, 'auto');
            }
            $manager = Orm::forConnection($probe);
            $repository = new ApplianceAssetRepository($manager);
            $integer = Type::getType(Types::INTEGER);
            $bigint = Type::getType(Types::BIGINT);
            try {
                foreach ($rootScopes as $rootScope) {
                    $probe->queries = [];
                    $this->integer($repository->ownerCount('Computer', (int)$scopedComputer->getID(), $rootScope->wrappedCriteria()))->isIdenticalTo(1);
                    $this->array($probe->queries[0]['types'])->isIdenticalTo(['bigint', 'integer']);
                    $sql = str_replace(['`', '"'], '', $probe->queries[0]['sql']);
                    $this->string($sql)->contains($rootScope->entityList ? '.entities_id IN (?)' : '.entities_id = ?');
                }
                Type::overrideType(Types::INTEGER, new class ($parent) extends IntegerType {
                    public function __construct(private int $offset)
                    {
                    }
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return '(' . $sqlExpr . ' + ' . $this->offset . ')';
                    }
                });
                foreach ($rootScopes as $rootScope) {
                    $this->integer($repository->ownerCount('Computer', (int)$scopedComputer->getID(), $rootScope->wrappedCriteria()))->isIdenticalTo(2);
                }
                Type::overrideType(Types::INTEGER, $integer);
                Type::overrideType(Types::BIGINT, new class () extends BigIntType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'CASE WHEN ' . $sqlExpr . ' = -1 THEN -1 ELSE -1 END';
                    }
                });
                $this->integer($repository->ownerCount('Computer', (int)$scopedComputer->getID(), []))->isIdenticalTo(0);
                $DB = $adapter;
                $this->integer(ApplianceItemModel::countForItem($scopedComputer))->isIdenticalTo(0);
            } finally {
                Type::overrideType(Types::INTEGER, $integer);
                Type::overrideType(Types::BIGINT, $bigint);
                $manager->clear();
                $DB = $originalAdapter;
            }

            $outerManager = null;
            $sentinel = null;
            Orm::withReadConnection($connection, function (EntityManager $manager) use ($scopedComputer, &$outerManager, &$sentinel): void {
                $outerManager = $manager;
                $sentinel = $manager->find(ComputerEntity::class, (int)$scopedComputer->getID());
                $this->object($sentinel)->isInstanceOf(ComputerEntity::class);
                $this->integer(ApplianceItemModel::countForItem($scopedComputer))->isIdenticalTo(3);
                $this->boolean($manager->contains($sentinel))->isTrue('A nested count must not clear the caller manager');
            });
            $this->boolean($outerManager->contains($sentinel))->isFalse();
            // Virtual item access cannot replace the route and scope already selected for this count.
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
            $probe->queries = [];
            $this->integer(ApplianceItemModel::countForItem($callbackItem))->isIdenticalTo(3);
            $this->object($DB)->isIdenticalTo($originalAdapter);
            $this->array(array_filter($probe->queries, static fn (array $query): bool =>
                str_contains($query['sql'], 'COUNT(') && str_contains($query['sql'], 'glpi_appliances')))->hasSize(1);
            $this->integer(ApplianceItemModel::countForItem($scopedComputer))->isIdenticalTo(0);
        } finally {
            $DB = $originalAdapter;
            $_SESSION = $scopeSession;
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
    public array $queries = [];

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

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
        return $this->selected->executeQuery($sql, $params, $types, $qcp);
    }
}
