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
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Item_Project as ItemProjectModel;
use LogicException;
use Project as ProjectModel;
use itsmng\Database\Entity\Computer as ComputerRecord;
use itsmng\Database\Entity\ItemProject as ItemProjectRecord;
use itsmng\Database\Orm;
use mock\DBmysql as ProjectAdapterProbe;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';


class Item_Project extends DbTestCase
{
    private function projectReadFixtures(): array
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $root = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $project = $this->createItem(ProjectModel::class, [
            'name' => 'Read project ' . $this->getUniqueString(), 'entities_id' => $root,
        ]);
        $computer = $this->createItem(ComputerModel::class, [
            'name' => 'Read computer ' . $this->getUniqueString(), 'entities_id' => $root,
        ]);
        $link = $this->createItem(ItemProjectModel::class, [
            'projects_id' => $project->getID(), 'itemtype' => ComputerModel::class, 'items_id' => $computer->getID(),
        ]);
        return [$project, $computer, $link, $root];
    }

    public function testProjectReadProjectionsStayFreshAndPreserveLiveOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        try {
            [$project, $computer, $link, $root] = $this->projectReadFixtures();
            $connection = $DB->getDoctrineConnection();
            $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
            $other = $this->createItem(ComputerModel::class, [
                'name' => 'Child project computer ' . $this->getUniqueString(), 'entities_id' => $child,
            ]);
            $otherLink = $this->createItem(ItemProjectModel::class, [
                'projects_id' => $project->getID(), 'itemtype' => ComputerModel::class, 'items_id' => $other->getID(),
            ]);
            $this->array(iterator_to_array(ItemProjectModel::getDistinctTypes((string)$project->getID())))
                ->isIdenticalTo([['itemtype' => ComputerModel::class]]);
            $rows = iterator_to_array(ItemProjectModel::getItemsAssociationRequest(ComputerModel::class, (string)$computer->getID()));
            $this->array($rows)->isIdenticalTo([[
                'id' => (int)$link->getID(), 'itemtype_1' => ProjectModel::class, 'items_id_1' => (int)$project->getID(),
                'itemtype_2' => ComputerModel::class, 'items_id_2' => (int)$computer->getID(), 'is_1' => 0, 'is_2' => 1,
            ]]);
            $this->integer((int)ItemProjectModel::getOppositeByTypeAndID(ComputerModel::class, $computer->getID())->getID())
                ->isIdenticalTo((int)$project->getID());
            $_SESSION['glpiactiveentities'] = [$root];
            $subjects = iterator_to_array(ItemProjectModel::getTypeItems((string)$project->getID(), ComputerModel::class));
            $this->array(array_map('intval', array_column($subjects, 'id')))->isIdenticalTo([(int)$computer->getID()]);
            $this->integer(ItemProjectModel::countForItem($computer))->isIdenticalTo(1);
            $_SESSION['glpiactiveentities'] = [$child];
            $this->array(array_map('intval', array_column(iterator_to_array(ItemProjectModel::getTypeItems($project->getID(), ComputerModel::class)), 'id')))
                ->isIdenticalTo([(int)$other->getID()]);
            $_SESSION['glpiactiveentities'] = [];
            $this->array(iterator_to_array(ItemProjectModel::getTypeItems($project->getID(), ComputerModel::class)))->isEmpty();
            $this->integer(ItemProjectModel::countForItem($computer))->isIdenticalTo(0);
            $_SESSION['glpiactiveentities'] = [$root];
            $writer = Orm::create($DB);
            $live = $writer->find(ItemProjectRecord::class, (int)$link->getID());
            $live->computer = $writer->find(ComputerRecord::class, (int)$other->getID());
            Orm::read($DB, function (EntityManager $outer) use ($link, $computer, $other, $writer, $live): void {
                $owned = $outer->find(ItemProjectRecord::class, (int)$link->getID());
                $owned->computer = $outer->find(ComputerRecord::class, (int)$other->getID());
                $this->integer(ItemProjectModel::countForItem($computer))->isIdenticalTo(1);
                $this->array(array_map('intval', array_column(iterator_to_array(ItemProjectModel::getItemsAssociationRequest(ComputerModel::class, $computer->getID())), 'items_id_2')))
                    ->isIdenticalTo([(int)$computer->getID()]);
                $this->boolean($outer->contains($owned))->isTrue();
                $this->integer($owned->computer->id)->isIdenticalTo((int)$other->getID());
                $this->boolean($writer->contains($live))->isTrue();
                $this->integer($live->computer->id)->isIdenticalTo((int)$other->getID());
            });
            $name = 'Current project computer ' . $this->getUniqueString();
            $this->integer($connection->update('glpi_computers', ['name' => $name], ['id' => $computer->getID()]))->isIdenticalTo(1);
            $current = iterator_to_array(ItemProjectModel::getTypeItems($project->getID(), ComputerModel::class));
            $this->array(array_column($current, 'name'))->isIdenticalTo([$name]);
            $this->array(array_column($subjects, 'name'))->isIdenticalTo([$computer->getField('name')]);
            $this->integer($connection->update('glpi_computers', ['is_template' => true], ['id' => $computer->getID()], ['is_template' => Types::BOOLEAN]))->isIdenticalTo(1);
            $this->array(iterator_to_array(ItemProjectModel::getTypeItems($project->getID(), ComputerModel::class)))->isEmpty();
            $this->integer($connection->delete('glpi_items_projects', ['id' => $link->getID()]))->isIdenticalTo(1);
            $this->integer(ItemProjectModel::countForItem($computer))->isIdenticalTo(0);
            $this->array(iterator_to_array(ItemProjectModel::getItemsAssociationRequest(ComputerModel::class, $computer->getID())))->isEmpty();
            $this->integer($connection->delete('glpi_items_projects', ['id' => $otherLink->getID()]))->isIdenticalTo(1);
            $this->array(iterator_to_array(ItemProjectModel::getDistinctTypes($project->getID())))->isEmpty();
            $self = $this->createItem(ItemProjectModel::class, [
                'projects_id' => $project->getID(), 'itemtype' => ProjectModel::class, 'items_id' => $project->getID(),
            ]);
            $roles = iterator_to_array(ItemProjectModel::getItemsAssociationRequest(ProjectModel::class, $project->getID()));
            $this->array($roles)->hasSize(1);
            $this->integer($roles[0]['id'])->isIdenticalTo((int)$self->getID());
            $this->integer($roles[0]['is_1'])->isIdenticalTo(1);
            $this->integer($roles[0]['is_2'])->isIdenticalTo(1);
            $this->boolean(ItemProjectModel::getOppositeByTypeAndID(ProjectModel::class, $project->getID()))->isFalse();
        } finally {
            $_SESSION = $session;
        }
    }

    public function testProjectReadPreparationPreservesCustomSelectedRouteAndFailureRecovery(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        try {
            [$project, $computer, $link] = $this->projectReadFixtures();
            $connection = $DB->getDoctrineConnection();
            $observer = new class () {
                public array $trace = [];
                public function postLoad(PostLoadEventArgs $event): void
                {
                    if ($event->getObject() instanceof ItemProjectRecord) {
                        $this->trace[] = 'loaded';
                    }
                }
            };
            $probe = new class ($connection) extends ScalarReadProbe {
                public EventManager $events;
                public object $observer;
                public function getEventManager(): EventManager
                {
                    $this->observer->trace[] = 'constructed';
                    return $this->events;
                }
            };
            $probe->observer = $observer;
            $probe->events = new EventManager();
            $probe->events->addEventListener(['postLoad'], $observer);
            $other = new ScalarReadProbe($connection);
            $route = $probe;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new ProjectAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = static function () use (&$route) {
                return $route;
            };
            $this->calling($adapter)->getProvider = $original->getProvider();
            $DB = $adapter;
            $kind = new class ($this, $connection, $observer, $other, $route) {
                public function __construct(private object $test, private Connection $connection, private object $observer, private Connection $other, private Connection &$route)
                {
                }
                public function __toString(): string
                {
                    $this->test->boolean($this->connection->isApplicationEntityManagerActive())->isFalse();
                    $this->observer->trace[] = 'converted';
                    $this->route = $this->other;
                    return ComputerModel::class;
                }
            };
            $rows = iterator_to_array(ItemProjectModel::getItemsAssociationRequest($kind, (string)$computer->getID()));
            $this->array($rows)->hasSize(1);
            $this->integer($rows[0]['id'])->isIdenticalTo((int)$link->getID());
            $this->array($observer->trace)->isIdenticalTo(['constructed', 'converted', 'loaded']);
            $this->array($other->queries)->isEmpty();
            $this->array($probe->queries)->isNotEmpty();
            $DB = $original;
            $bad = new class () {
                public function __toString(): string
                {
                    throw new LogicException('Project kind preparation failure');
                }
            };
            $this->exception(static fn () => ItemProjectModel::getItemsAssociationRequest($bad, $computer->getID()))
                ->isInstanceOf(LogicException::class)->hasMessage('Project kind preparation failure');
            $this->boolean($connection->isApplicationEntityManagerActive())->isFalse();
            $this->array(iterator_to_array(ItemProjectModel::getDistinctTypes(0)))->isEmpty();
            $this->array(iterator_to_array(ItemProjectModel::getItemsAssociationRequest(ComputerModel::class, $computer->getID())))->hasSize(1);
        } finally {
            $DB = $original;
            $_SESSION = $session;
        }
    }

    public function testDuplicateLinkIsRejected()
    {
        $this->login();

        $project = new \Project();
        $project_id = $project->add([
           'name' => 'item-project-' . $this->getUniqueString(),
        ]);
        $this->integer((int)$project_id)->isGreaterThan(0);

        $computer = getItemByTypeName('Computer', '_test_pc01');
        $this->object($computer)->isInstanceOf('\Computer');

        $obj = new \Item_Project();
        $first_id = $obj->add([
           'projects_id' => $project_id,
           'itemtype'    => 'Computer',
           'items_id'    => $computer->getID(),
        ]);
        $this->integer((int)$first_id)->isGreaterThan(0);

        $duplicate_id = $obj->add([
           'projects_id' => $project_id,
           'itemtype'    => 'Computer',
           'items_id'    => $computer->getID(),
        ]);
        $this->integer((int)$duplicate_id)->isEqualTo(0);
        $this->integer((int)countElementsInTable(
            \Item_Project::getTable(),
            [
                'projects_id' => $project_id,
                'itemtype'    => 'Computer',
                'items_id'    => $computer->getID(),
            ]
        ))->isEqualTo(1);
    }
}
