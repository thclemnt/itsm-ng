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

use Closure;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Computer as ComputerRecord;
use RuntimeException;
use tests\fixtures\ScalarReadProbe;
use Computer as ComputerModel;
use ComputerVirtualMachine as VirtualMachineModel;
use DbTestCase;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Orm;
use itsmng\Database\Repository\InventoryRepository;
use itsmng\Database\VirtualMachineCountReadOperation;
use LogicException;
use mock\DBmysql as AdapterProbe;
use ReflectionProperty;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

class ComputerVirtualMachine extends DbTestCase
{
    public function testUuidPreparationRetainsCustomRouteAndCurrentIdentity(): void
    {
        global $DB;
        $this->login();
        $database = $DB;
        $connection = $database->getDoctrineConnection();
        $uuid = '6742e6ac-b493-4e57-a964-35f2dba80699';
        $first = $this->createItem(ComputerModel::class, ['name' => $this->getUniqueString(), 'uuid' => $uuid, 'entities_id' => 0]);
        $second = $this->createItem(ComputerModel::class, ['name' => $this->getUniqueString(), 'uuid' => $uuid, 'entities_id' => 0]);
        $ambiguity = null;
        try {
            VirtualMachineModel::findVirtualMachine(['uuid' => $uuid]);
        } catch (RuntimeException $error) {
            if (
                $error->getFile() !== realpath(GLPI_ROOT . '/inc/toolbox.class.php')
                || !str_contains($error->getMessage(), 'ComputerVirtualMachine::findVirtualMachine()')
                || !str_contains($error->getMessage(), 'findVirtualMachine expects one result; at least two computers match the UUID.')
            ) {
                throw $error;
            }
            $ambiguity = $error;
        }
        $this->object($ambiguity)->isInstanceOf(RuntimeException::class);
        $observer = new class () {
            public array $trace = [];
            public int $clears = 0;
            public function onClear(): void
            {
                ++$this->clears;
            }
        };
        $probe = new class ($connection) extends ScalarReadProbe {
            public EventManager $events;
            public object $observer;
            public object $database;
            public function getEventManager(): EventManager
            {
                $this->observer->trace[] = 'constructed';
                $GLOBALS['DB'] = $this->database;
                return $this->events;
            }
        };
        $probe->events = new EventManager();
        $probe->events->addEventListener(['onClear'], $observer);
        $probe->observer = $observer;
        $probe->database = $database;
        $this->mockGenerator()->orphanize('__construct');
        $adapter = new AdapterProbe();
        $this->calling($adapter)->getDoctrineConnection = $probe;
        $convert = function () use ($observer, $connection, $second, $uuid): string {
            $this->boolean($connection->isApplicationEntityManagerActive())->isFalse();
            $observer->trace[] = 'converted';
            $connection->update('glpi_computers', ['uuid' => ''], ['id' => $second->getID()]);
            return strtoupper($uuid);
        };
        $value = new class ($convert) {
            public function __construct(private Closure $convert)
            {
            }
            public function __toString(): string
            {
                return ($this->convert)();
            }
        };
        try {
            $DB = $adapter;
            $this->integer(VirtualMachineModel::findVirtualMachine(['uuid' => $value]))->isIdenticalTo((int)$first->getID());
            $this->string($observer->trace[0])->isIdenticalTo('constructed');
            $this->string($observer->trace[1])->isIdenticalTo('converted');
            $this->array($probe->queries)->hasSize(1);
            $this->integer($observer->clears)->isIdenticalTo(0);
            $failure = new RuntimeException('UUID preparation refused');
            $invalid = new class ($failure) {
                public function __construct(private RuntimeException $failure)
                {
                }
                public function __toString(): string
                {
                    throw $this->failure;
                }
            };
            $DB = $adapter;
            $this->exception(static fn () => VirtualMachineModel::findVirtualMachine(['uuid' => $invalid]))->isIdenticalTo($failure);
            $this->array($probe->queries)->hasSize(1);
            $DB = $adapter;
            $this->integer(VirtualMachineModel::findVirtualMachine(['uuid' => $uuid]))->isIdenticalTo((int)$first->getID());
            $this->array($probe->queries)->hasSize(2);
            $this->integer($observer->clears)->isIdenticalTo(0);
        } finally {
            $DB = $database;
        }
    }

    public function testCreateAndGet()
    {
        $this->login();

        $this->newTestedInstance();
        $obj = $this->testedInstance;
        $uuid = 'c37f7ce8-af95-4676-b454-0959f2c5e162';

        // Add
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $this->object($computer)->isInstanceOf('\Computer');

        $this->integer(
            $id = (int)$obj->add([
              'computers_id' => $computer->fields['id'],
              'name'         => 'Virtu Hall',
              'uuid'         => $uuid,
              'vcpu'         => 1,
              'ram'          => 1024
         ])
        )->isGreaterThan(0);
        $this->boolean($obj->getFromDB($id))->isTrue();
        $this->string($obj->fields['uuid'])->isIdenticalTo($uuid);

        $database = $GLOBALS['DB'];
        $session = $_SESSION;
        $connection = $database->getDoctrineConnection();
        $manager = Orm::forConnection($connection);
        $repository = new InventoryRepository($manager);
        try {
            $host = (int)$computer->getID();
            $expected = $repository->countVirtualMachines($host);
            $_SESSION['glpishow_count_on_tabs'] = true;
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $before = $factories->getValue();
            $this->string($obj->getTabNameForItem($computer))->isIdenticalTo(
                VirtualMachineModel::createTabEntry(VirtualMachineModel::getTypeName(), $expected)
            );
            // The fixed tab count does not create an entity manager.
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
            $reader = new VirtualMachineCountReadOperation($connection);
            $this->integer($reader->forComputer($host))->isIdenticalTo($expected);
            foreach ([0, -1] as $missing) {
                $this->integer($reader->forComputer($missing))->isIdenticalTo($repository->countVirtualMachines($missing));
            }
            $duplicate = $this->createItem(VirtualMachineModel::class, [
                'name' => 'Duplicate inventory count', 'computers_id' => $host, 'uuid' => $uuid,
            ]);
            $this->integer($reader->forComputer($host))->isIdenticalTo($expected + 1);
            $this->boolean($database->update('glpi_computervirtualmachines', ['is_deleted' => true], ['id' => $duplicate->getID()]))->isTrue();
            $this->integer($reader->forComputer($host))->isIdenticalTo($expected);
            $this->boolean($database->update('glpi_computervirtualmachines', ['is_deleted' => false], ['id' => $duplicate->getID()]))->isTrue();
            $expected++;
            $this->integer($reader->forComputer($host))->isIdenticalTo($expected);

            $integer = Type::getType(Types::INTEGER);
            $boolean = Type::getType(Types::BOOLEAN);
            try {
                Type::overrideType(Types::INTEGER, new class () extends IntegerType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return '(' . $sqlExpr . ' + 1000000)';
                    }
                    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?int
                    {
                        throw new LogicException('The original scalar COUNT keeps its native value before the final int cast.');
                    }
                });
                $this->integer($reader->forComputer($host))->isIdenticalTo(0);
                $this->integer($repository->countVirtualMachines($host))->isIdenticalTo(0);
                Type::overrideType(Types::INTEGER, $integer);
                $flipped = new class () extends BooleanType {
                    public int $calls = 0;
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        ++$this->calls;
                        return '(NOT (' . $sqlExpr . '))';
                    }
                };
                Type::overrideType(Types::BOOLEAN, $flipped);
                $this->integer($reader->forComputer($host))->isIdenticalTo($repository->countVirtualMachines($host));
                $this->integer($flipped->calls)->isIdenticalTo(2);
            } finally {
                Type::overrideType(Types::INTEGER, $integer);
                Type::overrideType(Types::BOOLEAN, $boolean);
            }

            $events = new EventManager();
            $extended = new VirtualMachineCountConnectionProbe($connection, $events);
            $this->integer(InventoryRepository::projectedVirtualMachineCount(
                $extended,
                EntityRegistry::virtualMachineCountMapping(),
                $host
            ))->isIdenticalTo($expected);
            $this->integer($extended->queries)->isIdenticalTo(1);
            $this->mockGenerator->orphanize('__construct');
            $adapter = new AdapterProbe();
            $routes = 0;
            $this->calling($adapter)->getDoctrineConnection = static function () use ($extended, &$routes): Connection {
                ++$routes;
                return $extended;
            };
            $this->calling($adapter)->getProvider = $database->getProvider();
            $late = new class ($database) extends ComputerModel {
                public function __construct(private $selected)
                {
                }
                public static function getType()
                {
                    return 'Computer';
                }
                public function getID()
                {
                    $GLOBALS['DB'] = $this->selected;
                    return parent::getID();
                }
            };
            $late->fields = $computer->fields;
            $GLOBALS['DB'] = $adapter;
            $queries = $extended->queries;
            $this->string($obj->getTabNameForItem($late))->isIdenticalTo(
                VirtualMachineModel::createTabEntry(VirtualMachineModel::getTypeName(), $expected)
            );
            $this->object($GLOBALS['DB'])->isIdenticalTo($database);
            $this->integer($routes)->isIdenticalTo(1);
            $this->integer($extended->queries - $queries)->isIdenticalTo(1);
            $_SESSION['glpishow_count_on_tabs'] = false;
            $before = $factories->getValue();
            $this->string($obj->getTabNameForItem($computer))->isIdenticalTo(
                VirtualMachineModel::createTabEntry(VirtualMachineModel::getTypeName(), 0)
            );
            $this->string($obj->getTabNameForItem($computer, 1))->isEmpty();
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
        } finally {
            $manager->clear();
            $GLOBALS['DB'] = $database;
            $_SESSION = $session;
        }


        $this->boolean($obj->findVirtualMachine(['name' => 'Virtu Hall']))->isFalse();
        //n machin exists yet
        $this->boolean($obj->findVirtualMachine(['uuid' => $uuid]))->isFalse();

        $this->integer(
            $cid = (int)$computer->add([
              'name'         => 'Virtu Hall',
              'uuid'         => $uuid,
              'entities_id'  => 0
         ])
        )->isGreaterThan(0);

        $this->variable($obj->findVirtualMachine(['uuid' => $uuid]))->isEqualTo($cid);

        $connection = $database->getDoctrineConnection();
        $owner = Orm::create($database);
        $displaySession = $_SESSION;
        $this->setEntity(0, true);
        try {
            $pending = $owner->find(ComputerRecord::class, $cid);
            $pending->name = 'Pending independent UUID owner';
            $hostComputer = new ComputerModel();
            $this->boolean($hostComputer->getFromDB($host))->isTrue();
            $comment = $this->getUniqueString();
            $this->integer($connection->update('glpi_computervirtualmachines', ['comment' => $comment], ['id' => $id]))->isIdenticalTo(1);
            ob_start();
            try {
                VirtualMachineModel::showForComputer($hostComputer);
                $this->string(ob_get_contents())->contains($comment)->contains($uuid);
            } finally {
                ob_end_clean();
            }
            ob_start();
            try {
                VirtualMachineModel::showForVirtualMachine($computer);
                $this->string(ob_get_contents())->contains($hostComputer->fields['name']);
            } finally {
                ob_end_clean();
            }
            $this->boolean($owner->contains($pending))->isTrue();
            $this->string($pending->name)->isIdenticalTo('Pending independent UUID owner');
            Orm::read($database, function (EntityManager $outer) use ($cid, $uuid): void {
                $live = $outer->find(ComputerRecord::class, $cid);
                $live->name = 'Pending enclosing UUID owner';
                $this->integer(VirtualMachineModel::findVirtualMachine(['uuid' => strtoupper($uuid)]))->isIdenticalTo($cid);
                $this->boolean($outer->contains($live))->isTrue();
                $this->string($live->name)->isIdenticalTo('Pending enclosing UUID owner');
            });
            $fresh = new ComputerModel();
            $this->boolean($fresh->getFromDB($cid))->isTrue();
            $this->string($fresh->fields['name'])->isIdenticalTo('Virtu Hall');
        } finally {
            $owner->clear();
            $_SESSION = $displaySession;
        }

    }
}


/** A real fixture connection with externally mutable metadata callbacks. */
class VirtualMachineCountConnectionProbe extends Connection
{
    public int $queries = 0;

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
        return $this->selected->getDatabasePlatform();
    }

    public function createQueryBuilder(): QueryBuilder
    {
        throw new LogicException('The fixed projection must not introduce a connection query-builder callback.');
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        ++$this->queries;
        return $this->selected->executeQuery($sql, $params, $types, $qcp);
    }
}
