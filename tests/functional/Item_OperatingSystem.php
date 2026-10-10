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
use Computer as ComputerModel;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use Item_OperatingSystem as ItemOperatingSystemModel;
use itsmng\Database\Entity\Computer as ComputerEntity;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\ItemOperatingSystem;
use itsmng\Database\Entity\OperatingSystem as OperatingSystemEntity;
use itsmng\Database\Entity\OperatingSystemArchitecture;
use itsmng\Database\Entity\OperatingSystemServicePack;
use itsmng\Database\Entity\OperatingSystemVersion;
use itsmng\Database\Orm;

/* Test for inc/item_operatingsystem.class.php */

class Item_OperatingSystem extends DbTestCase
{
    public function testCompletedAssignmentRowsKeepLabelsOrderingAndLiveOwners(): void
    {
        global $DB;
        $this->login();
        $fixture = Orm::create($DB);
        $root = $fixture->getReference(Entity::class, (int)getItemByTypeName('Entity', '_test_root_entity', true));
        $computer = new ComputerEntity();
        $computer->entities = $root;
        $computer->name = 'OS row subject';
        $fixture->persist($computer);
        $other = new ComputerEntity();
        $other->entities = $root;
        $fixture->persist($other);
        $labels = [];
        foreach ([OperatingSystemEntity::class => 'Alpha OS', OperatingSystemVersion::class => 'Version label',
            OperatingSystemArchitecture::class => 'Architecture label', OperatingSystemServicePack::class => 'Service pack label'] as $class => $name) {
            $label = new $class();
            $label->name = $name;
            $fixture->persist($label);
            $labels[$class] = $label;
        }
        $zulu = new OperatingSystemEntity();
        $zulu->name = 'Zulu OS';
        $fixture->persist($zulu);
        $rows = [];
        foreach ([$labels[OperatingSystemEntity::class], $zulu, null] as $index => $os) {
            $row = new ItemOperatingSystem();
            $row->entities = $root;
            $row->itemtype = 'Computer';
            $row->computer = $computer;
            $row->operatingsystems = $os;
            if ($index === 1) {
                $row->operatingsystemversions = $labels[OperatingSystemVersion::class];
                $row->operatingsystemarchitectures = $labels[OperatingSystemArchitecture::class];
                $row->operatingsystemservicepacks = $labels[OperatingSystemServicePack::class];
            }
            $row->is_deleted = $index === 2;
            $fixture->persist($row);
            $rows[] = $row;
        }
        $unrelated = new ItemOperatingSystem();
        $unrelated->entities = $root;
        $unrelated->itemtype = 'Computer';
        $unrelated->computer = $other;
        $unrelated->operatingsystems = $labels[OperatingSystemEntity::class];
        $fixture->persist($unrelated);
        $fixture->flush();
        $model = new ComputerModel();
        $this->boolean($model->getFromDB($computer->id))->isTrue();
        $expected = [
            ['assocID' => $rows[0]->id, 'name' => 'Alpha OS', 'version' => null, 'architecture' => null, 'servicepack' => null],
            ['assocID' => $rows[1]->id, 'name' => 'Zulu OS', 'version' => 'Version label', 'architecture' => 'Architecture label', 'servicepack' => 'Service pack label'],
            ['assocID' => $rows[2]->id, 'name' => null, 'version' => null, 'architecture' => null, 'servicepack' => null],
        ];
        $this->array(ItemOperatingSystemModel::getFromItem($model))->isIdenticalTo($expected);
        $ascending = [$expected[2], $expected[0], $expected[1]];
        $this->array(ItemOperatingSystemModel::getFromItem($model, 'name', 'asc'))->isIdenticalTo($ascending);
        $this->array(ItemOperatingSystemModel::getFromItem($model, '0', 'DESC'))->isIdenticalTo(array_reverse($ascending));
        $missing = new ComputerModel();
        $missing->fields['id'] = 0;
        $this->array(ItemOperatingSystemModel::getFromItem($missing))->isEmpty();

        $computer->name = 'Pending independent OS subject';
        Orm::read($DB, function (EntityManager $owner) use ($model, $computer, $expected, $fixture): void {
            $managed = $owner->find(ComputerEntity::class, $computer->id);
            $managed->name = 'Pending outer OS subject';
            $this->array(ItemOperatingSystemModel::getFromItem($model))->isIdenticalTo($expected);
            $this->exception(static fn () => ItemOperatingSystemModel::getFromItem($model, 'unknown'))
                ->isInstanceOf(InvalidArgumentException::class)->hasMessage('Unsupported OS sort field');
            $this->boolean($owner->contains($managed))->isTrue();
            $this->string($managed->name)->isIdenticalTo('Pending outer OS subject');
            $this->boolean($fixture->contains($computer))->isTrue();
            $this->string($computer->name)->isIdenticalTo('Pending independent OS subject');
            $this->string($owner->getConnection()->fetchOne('SELECT name FROM glpi_computers WHERE id=?', [$computer->id]))->isIdenticalTo('OS row subject');
        });
        $this->exception(static fn () => ItemOperatingSystemModel::getFromItem($model, null, 'unknown'))
            ->isInstanceOf(InvalidArgumentException::class)->hasMessage('Unsupported OS sort direction');
        $this->array(ItemOperatingSystemModel::getFromItem($model))->isIdenticalTo($expected);

        // Public Stringable sort conversion can perform another ordinary read.
        $sort = new class ($this, $DB) {
            public function __construct(private $test, private $database)
            {
            }
            public function __toString(): string
            {
                Orm::read($this->database, function (EntityManager $manager): void {
                    $this->test->boolean($manager->getConnection()->ownsApplicationEntityManager($manager))->isTrue();
                });
                return 'name';
            }
        };
        $this->array(ItemOperatingSystemModel::getFromItem($model, $sort, 'ASC'))->isIdenticalTo($ascending);
    }

    public function testGetTypeName()
    {
        $this->string(\Item_OperatingSystem::getTypeName())->isIdenticalTo('Item operating systems');
        $this->string(\Item_OperatingSystem::getTypeName(0))->isIdenticalTo('Item operating systems');
        $this->string(\Item_OperatingSystem::getTypeName(10))->isIdenticalTo('Item operating systems');
        $this->string(\Item_OperatingSystem::getTypeName(1))->isIdenticalTo('Item operating system');
    }

    /**
     * Create dropdown objects to be used
     *
     * @return array
     */
    private function createDdObjects()
    {
        $objects = [];
        foreach (['', 'Architecture', 'Version', 'Edition', 'KernelVersion'] as $object) {
            $classname = 'OperatingSystem' . $object;
            $instance = new $classname();
            $this->integer(
                (int)$instance->add([
                  'name' => $classname . ' ' . $this->getUniqueInteger()
            ])
            )->isGreaterThan(0);
            $this->boolean($instance->getFromDB($instance->getID()))->isTrue();
            $objects[$object] = $instance;
        }
        return $objects;
    }

    public function testAttachComputer()
    {
        $computer = getItemByTypeName('Computer', '_test_pc01');

        $objects = $this->createDdObjects();
        $ios = new \Item_OperatingSystem();
        $input = [
           'itemtype'                          => $computer->getType(),
           'items_id'                          => $computer->getID(),
           'operatingsystems_id'               => $objects['']->getID(),
           'operatingsystemarchitectures_id'   => $objects['Architecture']->getID(),
           'operatingsystemversions_id'        => $objects['Version']->getID(),
           'operatingsystemkernelversions_id'  => $objects['KernelVersion']->getID(),
           'licenseid'                         => $this->getUniqueString(),
           'license_number'                    => $this->getUniqueString()
        ];
        $this->integer(
            (int)$ios->add($input)
        )->isGreaterThan(0);
        $this->boolean($ios->getFromDB($ios->getID()))->isTrue();

        $this->string($ios->getTabNameForItem($computer))
           ->isIdenticalTo("Operating systems <sup class='tab_nb'>1</sup>");
        $this->integer(
            (int)\Item_OperatingSystem::countForItem($computer)
        )->isIdenticalTo(1);

        $assignmentId = $ios->getID();
        $this->boolean($ios->add($input))->isFalse();
        $this->hasSessionMessages(ERROR, ['An operating system with this architecture is already assigned to this item.']);
        $this->boolean($ios->getFromDB($assignmentId))->isTrue();
        $this->string($ios->fields['licenseid'])->isIdenticalTo($input['licenseid']);
        $this->string($ios->fields['license_number'])->isIdenticalTo($input['license_number']);

        $this->integer(
            (int)\Item_OperatingSystem::countForItem($computer)
        )->isIdenticalTo(1);

        $objects = $this->createDdObjects();
        $ios = new \Item_OperatingSystem();
        $input = [
           'itemtype'                          => $computer->getType(),
           'items_id'                          => $computer->getID(),
           'operatingsystems_id'               => $objects['']->getID(),
           'operatingsystemarchitectures_id'   => $objects['Architecture']->getID(),
           'operatingsystemversions_id'        => $objects['Version']->getID(),
           'operatingsystemkernelversions_id'  => $objects['KernelVersion']->getID(),
           'licenseid'                         => $this->getUniqueString(),
           'license_number'                    => $this->getUniqueString()
        ];
        $this->integer(
            (int)$ios->add($input)
        )->isGreaterThan(0);
        $this->boolean($ios->getFromDB($ios->getID()))->isTrue();

        $this->string($ios->getTabNameForItem($computer))
           ->isIdenticalTo("Operating systems <sup class='tab_nb'>2</sup>");
        $this->integer(
            (int)\Item_OperatingSystem::countForItem($computer)
        )->isIdenticalTo(2);
    }

    public function testShowForItem()
    {
        $this->login();
        $computer = getItemByTypeName('Computer', '_test_pc01');

        foreach (['showForItem', 'displayTabContentForItem'] as $method) {
            $this->output(
                function () use ($method, $computer) {
                    \Item_OperatingSystem::$method($computer);
                }
            )->contains('operatingsystems_id');
        }

        $objects = $this->createDdObjects();
        $ios = new \Item_OperatingSystem();
        $input = [
           'itemtype'                          => $computer->getType(),
           'items_id'                          => $computer->getID(),
           'operatingsystems_id'               => $objects['']->getID(),
           'operatingsystemarchitectures_id'   => $objects['Architecture']->getID(),
           'operatingsystemversions_id'        => $objects['Version']->getID(),
           'operatingsystemkernelversions_id'  => $objects['KernelVersion']->getID(),
           'licenseid'                         => $this->getUniqueString(),
           'license_number'                    => $this->getUniqueString()
        ];
        $this->integer(
            (int)$ios->add($input)
        )->isGreaterThan(0);
        $this->boolean($ios->getFromDB($ios->getID()))->isTrue();

        foreach (['showForItem', 'displayTabContentForItem'] as $method) {
            $this->output(
                function () use ($method, $computer) {
                    \Item_OperatingSystem::$method($computer);
                }
            )->contains('operatingsystems_id');
        }

        $objects = $this->createDdObjects();
        $ios = new \Item_OperatingSystem();
        $input = [
           'itemtype'                          => $computer->getType(),
           'items_id'                          => $computer->getID(),
           'operatingsystems_id'               => $objects['']->getID(),
           'operatingsystemarchitectures_id'   => $objects['Architecture']->getID(),
           'operatingsystemversions_id'        => $objects['Version']->getID(),
           'operatingsystemkernelversions_id'  => $objects['KernelVersion']->getID(),
           'licenseid'                         => $this->getUniqueString(),
           'license_number'                    => $this->getUniqueString()
        ];
        $this->integer(
            (int)$ios->add($input)
        )->isGreaterThan(0);
        $this->boolean($ios->getFromDB($ios->getID()))->isTrue();

        //thera are now 2 OS linked, we will no longer show a form, but a list.
        foreach (['showForItem', 'displayTabContentForItem'] as $method) {
            $this->output(
                function () use ($method, $computer) {
                    \Item_OperatingSystem::$method($computer);
                }
            )->notContains('operatingsystems_id');
        }
    }

    public function testEntityAccess()
    {
        $this->login();
        $eid = getItemByTypeName('Entity', '_test_root_entity', true);
        $this->setEntity('_test_root_entity', true);

        $computer = new \Computer();
        $this->integer(
            (int)$computer->add([
              'name'         => 'Test Item/OS',
              'entities_id'  => $eid,
              'is_recursive' => 0
         ])
        )->isGreaterThan(0);

        $os = new \OperatingSystem();
        $this->integer(
            (int)$os->add([
              'name' => 'Test OS'
         ])
        )->isGreaterThan(0);

        $ios = new \Item_OperatingSystem();
        $this->integer(
            (int)$ios->add([
              'operatingsystems_id'   => $os->getID(),
              'itemtype'              => 'Computer',
              'items_id'              => $computer->getID()
         ])
        )->isGreaterThan(0);
        $this->boolean($ios->getFromDB($ios->getID()))->isTrue();

        $this->array($ios->fields)
           ->integer['operatingsystems_id']->isIdenticalTo($os->getID())
           ->string['itemtype']->isIdenticalTo('Computer')
           ->integer['items_id']->isIdenticalTo($computer->getID())
           ->integer['entities_id']->isIdenticalTo($eid)
           ->integer['is_recursive']->isIdenticalTo(0);

        $this->boolean($ios->can($ios->getID(), READ))->isTrue();

        //not recursive
        $this->setEntity(0, true);
        $this->boolean($ios->can($ios->getID(), READ))->isTrue();
        $this->setEntity('_test_child_1', true);
        $this->boolean($ios->can($ios->getID(), READ))->isFalse();
        $this->setEntity('_test_child_2', true);
        $this->boolean($ios->can($ios->getID(), READ))->isFalse();

        $this->setEntity('_test_root_entity', true);
        $this->boolean(
            (bool)$computer->update([
              'id'           => $computer->getID(),
              'is_recursive' => 1
         ])
        )->isTrue();
        $this->boolean($ios->getFromDB($ios->getID()))->isTrue();
        $this->array($ios->fields)
           ->integer['operatingsystems_id']->isIdenticalTo($os->getID())
           ->string['itemtype']->isIdenticalTo('Computer')
           ->integer['items_id']->isIdenticalTo($computer->getID())
           ->integer['entities_id']->isIdenticalTo($eid)
           ->integer['is_recursive']->isIdenticalTo(1);

        //not recursive
        $this->setEntity(0, true);
        $this->boolean($ios->can($ios->getID(), READ))->isTrue();
        $this->setEntity('_test_child_1', true);
        $this->boolean($ios->can($ios->getID(), READ))->isTrue();
        $this->setEntity('_test_child_2', true);
        $this->boolean($ios->can($ios->getID(), READ))->isTrue();
    }
}
