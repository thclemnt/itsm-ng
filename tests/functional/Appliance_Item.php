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

use DbTestCase;

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

        $appliance = new \Appliance();

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
        $this->integer(\Appliance_Item::countForMainItem($appliance))->isIdenticalTo(0);

        $this->login();
        $this->integer(\Appliance_Item::countForMainItem($appliance))->isIdenticalTo(3);

        $this->boolean($appliance->getFromDB($appliance_2))->isTrue();
        $this->integer(\Appliance_Item::countForMainItem($appliance))->isIdenticalTo(2);

        $connection = $DB->getDoctrineConnection();
        $manager = \itsmng\Database\Orm::forConnection($connection);
        $repository = new \itsmng\Database\Repository\ApplianceAssetRepository($manager);
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $expected = $repository->ownerCount('Computer', (int)$computer->getID(), []);
        $this->integer($expected)->isGreaterThanOrEqualTo(2);
        $probe = new ApplianceOwnerCountProbe($connection);
        $originalAdapter = $DB;
        $originalSession = $_SESSION;
        $this->mockGenerator->orphanize('__construct');
        $adapter = new \mock\DBmysql();
        $this->calling($adapter)->getDoctrineConnection = $probe;
        $this->calling($adapter)->getProvider = $originalAdapter->getProvider();
        try {
            $_SESSION['glpishowallentities'] = true;
            $DB = $adapter;
            $this->integer(\Appliance_Item::countForItem($computer))->isIdenticalTo($expected);
            $this->array($probe->queries)->hasSize(1);
            $this->integer($probe->builders)->isIdenticalTo(1);
        } finally {
            $DB = $originalAdapter;
            $_SESSION = $originalSession;
        }
        $reader = new \itsmng\Database\ApplianceOwnerReadOperation($probe);
        try {
            foreach ([[], ['glpi_appliances.entities_id' => 0], ['glpi_appliances.entities_id' => [0, '1']],
                ['glpi_appliances.entities_id' => []], ['OR' => ['glpi_appliances.entities_id' => 0, 'glpi_appliances.is_recursive' => true]]] as $criteria) {
                if ($criteria === ['glpi_appliances.entities_id' => []]) {
                    $this->exception(static fn () => $reader->ownerCount('Computer', (int)$computer->getID(), $criteria))->isInstanceOf(\RuntimeException::class);
                    continue;
                }
                $before = $probe->builders;
                $this->integer($reader->ownerCount('Computer', (int)$computer->getID(), $criteria))
                    ->isIdenticalTo($repository->ownerCount('Computer', (int)$computer->getID(), $criteria));
                $this->integer($probe->builders - $before)->isIdenticalTo(isset($criteria['OR']) ? 0 : 1);
            }
            $this->integer($reader->ownerCount('Computer', 0, []))->isIdenticalTo(0);
            $this->integer($reader->ownerCount('UnknownApplianceAsset', (int)$computer->getID(), []))->isIdenticalTo(0);
            $originalType = \Doctrine\DBAL\Types\Type::getType(\Doctrine\DBAL\Types\Types::BIGINT);
            try {
                \Doctrine\DBAL\Types\Type::overrideType(\Doctrine\DBAL\Types\Types::BIGINT, new class () extends \Doctrine\DBAL\Types\BigIntType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, \Doctrine\DBAL\Platforms\AbstractPlatform $platform): string
                    {
                        return 'CASE WHEN ' . $sqlExpr . ' = -1 THEN -1 ELSE -1 END';
                    }
                });
                $this->integer($reader->ownerCount('Computer', (int)$computer->getID(), []))->isIdenticalTo(0);
                $this->integer($repository->ownerCount('Computer', (int)$computer->getID(), []))->isIdenticalTo(0);
            } finally {
                \Doctrine\DBAL\Types\Type::overrideType(\Doctrine\DBAL\Types\Types::BIGINT, $originalType);
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
           'FROM'   => \Appliance_Item::getTable(),
           'WHERE'  => ['appliances_id' => [$appliance_1, $appliance_2]]
        ]);
        $this->integer(count($iterator))->isIdenticalTo(0);
    }
}

/** Observe the selected transaction without a second socket. */
class ApplianceOwnerCountProbe extends \Doctrine\DBAL\Connection
{
    public int $builders = 0;
    public array $queries = [];

    public function __construct(private readonly \Doctrine\DBAL\Connection $selected)
    {
        parent::__construct($selected->getParams(), $selected->getDriver(), $selected->getConfiguration());
    }

    public function getDatabasePlatform(): \Doctrine\DBAL\Platforms\AbstractPlatform
    {
        return $this->selected->getDatabasePlatform();
    }

    public function createQueryBuilder(): \Doctrine\DBAL\Query\QueryBuilder
    {
        ++$this->builders;
        return parent::createQueryBuilder();
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?\Doctrine\DBAL\Cache\QueryCacheProfile $qcp = null): \Doctrine\DBAL\Result
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
        return $this->selected->executeQuery($sql, $params, $types, $qcp);
    }
}
