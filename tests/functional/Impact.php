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

use Change;
use Change_Item;
use CommonDBTM;
use Computer;
use Config as ConfigModel;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use ReflectionProperty;
use InvalidArgumentException;
use LogicException;
use mock\DBmysql as ImpactAdapterProbe;
use mock\Computer as ImpactComputerProbe;
use tests\fixtures\ScalarReadProbe;
use Impact as ImpactModel;
use ImpactCompound;
use ImpactItem;
use ImpactRelation;
use Item_Problem;
use Item_Ticket;
use Problem;
use Session;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Ticket;
use Toolbox;
use itsmng\Database\Entity;
use itsmng\Database\Entity\User;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ITILAssetRepository;
use itsmng\Database\Repository\TicketAssetRepository;
use itsmng\Database\Repository\UserRepository;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

class Impact extends \DbTestCase
{
    public function testListPriorityColorsUseCurrentAccountOverrides(): void
    {
        global $DB, $CFG_GLPI;

        $this->login();
        $this->setEntity('_test_root_entity', true);
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $em = Orm::create($DB);
        $connection = $em->getConnection();
        $level = $connection->getTransactionNestingLevel();
        $savedConfig = $CFG_GLPI;
        $user = (int)Session::getLoginUserID();
        $observer = new class () {
            public int $loads = 0;
            public function postLoad(PostLoadEventArgs $event): void
            {
                ++$this->loads;
            }
        };
        $em->getEventManager()->addEventListener(['postLoad'], $observer);
        try {
            $computers = [];
            for ($i = 0; $i < 3; ++$i) {
                $computer = new Computer();
                $this->integer($computer->add([
                    'name' => 'Impact priority ' . $i . '-' . bin2hex(random_bytes(6)),
                    'entities_id' => $entity,
                ]))->isGreaterThan(0);
                $this->boolean($computer->can($computer->getID(), READ))->isTrue();
                $computers[] = $computer;
            }
            $connection->update('glpi_users', [
                'priority_3' => null, 'priority_4' => '', 'priority_5' => '#123456', 'priority_6' => '0',
            ], ['id' => $user]);
            $CFG_GLPI['priority_3'] = '#abcdef';
            $repository = new UserRepository($em);
            $colors = $repository->priorityColors($user);
            $this->array($colors)->hasSize(6);
            $this->variable($colors['priority_3'])->isNull();
            $this->string($colors['priority_4'])->isIdenticalTo('');
            $this->string($colors['priority_5'])->isIdenticalTo('#123456');
            $this->string($colors['priority_6'])->isIdenticalTo('0');
            $this->array($repository->priorityColors(-1))->isEmpty();
            $this->integer($observer->loads)->isIdenticalTo(0);
            $this->integer($em->getUnitOfWork()->size())->isIdenticalTo(0);
            $managed = $em->find(User::class, $user);
            $this->integer($observer->loads)->isGreaterThan(0);

            // The renderer consumes an already built graph; keep its counters and
            // priorities explicit so maximum-priority and empty-cell behavior are tested.
            $graph = ['nodes' => [], 'edges' => []];
            foreach ($computers as $computer) {
                $node = ImpactModel::getNodeID($computer);
                $graph['nodes'][$node] = ['id' => $node, 'label' => $computer->fields['name'],
                    'ITILObjects' => ['incidents' => [], 'problems' => [], 'changes' => []]];
            }
            $root = ImpactModel::getNodeID($computers[0]);
            $first = ImpactModel::getNodeID($computers[1]);
            $second = ImpactModel::getNodeID($computers[2]);
            $graph['nodes'][$first]['ITILObjects'] = [
                'incidents' => [['priority' => 2], ['priority' => 5]],
                'problems' => [['priority' => 3]], 'changes' => [['priority' => 4]],
            ];
            $graph['nodes'][$second]['ITILObjects']['incidents'] = [['priority' => 6]];
            foreach ([$first, $second] as $node) {
                $graph['edges'][] = ['source' => $root, 'target' => $node, 'flag' => ImpactModel::DIRECTION_FORWARD];
            }
            $render = static function () use ($computers, &$graph): string {
                ob_start();
                try {
                    ImpactModel::displayListView($computers[0], $graph);
                    return ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            };
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $beforeFactories = $factories->getValue();
            $html = $render();
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0, 'Priority rendering reuses the existing request manager');
            foreach (['#123456', '#abcdef', '', '0'] as $color) {
                $this->string($html)->contains('background-color:' . $color . '; cursor:pointer;');
            }
            $this->string($html)->contains('<div>2</div>')->contains('<div></div>');
            $this->string($html)->contains('itemtype=Ticket')->contains('itemtype=Problem')->contains('itemtype=Change');
            $this->string($html)->contains($computers[1]->fields['name'])->contains($computers[2]->fields['name']);

            $connection->update('glpi_users', ['priority_5' => '#654321'], ['id' => $user]);
            $CFG_GLPI['priority_3'] = '#fedcba';
            $this->string($repository->priorityColors($user)['priority_5'])->isIdenticalTo('#654321');
            $this->string($managed->priority_5)->isIdenticalTo('#123456');
            $html = $render();
            $this->string($html)->contains('background-color:#654321;')->contains('background-color:#fedcba;');
            $this->string($html)->notContains('background-color:#123456;')->notContains('background-color:#abcdef;');
            foreach ($graph['nodes'] as &$node) {
                $node['ITILObjects'] = ['incidents' => [], 'problems' => [], 'changes' => []];
            }
            unset($node);
            $this->string($render())->notContains('background-color:');
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $em->clear();
            $CFG_GLPI = $savedConfig;
        }
    }

    public function beforeTestMethod($method)
    {
        parent::beforeTestMethod($method);

        foreach ($this->nodes as $node) {
            $id = $node['id'];
            $this->graph['nodes'][$id] = $node;
        }

        foreach ($this->forward as $edge) {
            $this->graph['edges'][] = [
               'source' => $edge[0],
               'target' => $edge[1],
               'flag'   => \Impact::DIRECTION_FORWARD,
            ];
        }

        foreach ($this->backward as $edge) {
            $this->graph['edges'][] = [
               'source' => $edge[0],
               'target' => $edge[1],
               'flag'   => \Impact::DIRECTION_BACKWARD,
            ];
        }

        foreach ($this->both as $edge) {
            $this->graph['edges'][] = [
               'source' => $edge[0],
               'target' => $edge[1],
               'flag'   => \Impact::DIRECTION_FORWARD | \Impact::DIRECTION_BACKWARD,
            ];
        }
    }

    protected function addDbCompound(string $name, string $color): int
    {
        $em = new ImpactCompound();
        $id = $em->add([
           'name'  => $name,
           'color' => $color,
        ]);

        $this->integer($id);
        return $id;
    }

    protected function addDbNode(CommonDBTM $item, int $parent = 0): int
    {
        $em = new ImpactItem();
        $id = $em->add([
           'itemtype'  => $item->getType(),
           'items_id'  => $item->fields['id'],
           'parent_id' => $parent,
        ]);

        $this->integer($id);
        return $id;
    }

    protected function addDbEdge(CommonDBTM $source, CommonDBTM $impacted): int
    {
        $em = new ImpactRelation();
        $id = $em->add([
           'itemtype_source'   => $source->getType(),
           'items_id_source'   => $source->fields['id'],
           'itemtype_impacted' => $impacted->getType(),
           'items_id_impacted' => $impacted->fields['id'],
        ]);

        $this->integer($id);
        return $id;
    }

    public function testGetTabNameForItem_notCommonDBTM()
    {
        $impact = new \Impact();

        $this->exception(function () use ($impact) {
            $notCommonDBTM = new \Impact();
            $impact->getTabNameForItem($notCommonDBTM);
        })->isInstanceOf(\InvalidArgumentException::class);
    }

    public function testGetTabNameForItem_notEnabledOrITIL()
    {
        $impact = new \Impact();

        $this->exception(function () use ($impact) {
            $not_enabled_or_itil = new ImpactCompound();
            $impact->getTabNameForItem($not_enabled_or_itil);
        })->isInstanceOf(\InvalidArgumentException::class);
        global $CFG_GLPI;
        $original = ConfigModel::getConfigurationValues('core', [ImpactModel::CONF_ENABLED]);
        $allowed = $CFG_GLPI['impact_asset_types'];
        try {
            $CFG_GLPI['impact_asset_types'][Computer::class] = true;
            ConfigModel::setConfigurationValues('core', [ImpactModel::CONF_ENABLED => exportArrayToDB([Computer::class, 'ForbiddenConfigurationItem'])]);
            $this->array(ImpactModel::getEnabledItemtypes())->isIdenticalTo([Computer::class]);
            unset($CFG_GLPI['impact_asset_types'][Computer::class]);
            $this->array(ImpactModel::getEnabledItemtypes())->isEmpty();
            $CFG_GLPI['impact_asset_types'][Computer::class] = true;
            ConfigModel::setConfigurationValues('core', [ImpactModel::CONF_ENABLED => exportArrayToDB([])]);
            $this->array(ImpactModel::getEnabledItemtypes())->isEmpty();
            ConfigModel::deleteConfigurationValues('core', [ImpactModel::CONF_ENABLED]);
            $this->array(ImpactModel::getEnabledItemtypes())->isEmpty();
        } finally {
            $CFG_GLPI['impact_asset_types'] = $allowed;
            if (array_key_exists(ImpactModel::CONF_ENABLED, $original)) {
                ConfigModel::setConfigurationValues('core', $original);
            } else {
                ConfigModel::deleteConfigurationValues('core', [ImpactModel::CONF_ENABLED]);
            }
        }
    }

    public function testGetTabNameForItem_tabCountDisabled()
    {
        $old_session = $_SESSION['glpishow_count_on_tabs'];
        $_SESSION['glpishow_count_on_tabs'] = false;

        $impact = new \Impact();
        $computer = new Computer();
        $tab_name = $impact->getTabNameForItem($computer);
        $_SESSION['glpishow_count_on_tabs'] = $old_session;

        $this->string($tab_name)->isEqualTo("Impact analysis");
    }

    public function testGetTabNameForItem_enabledAsset()
    {
        global $DB;
        $old_session = $_SESSION['glpishow_count_on_tabs'];
        $original = ConfigModel::getConfigurationValues('core', [ImpactModel::CONF_ENABLED]);
        $connection = $DB->getDoctrineConnection();
        try {
            $_SESSION['glpishow_count_on_tabs'] = true;
            ConfigModel::setConfigurationValues('core', [ImpactModel::CONF_ENABLED => exportArrayToDB([Computer::class])]);
            $impact = new ImpactModel();
            $computer1 = getItemByTypeName('Computer', '_test_pc01');
            $computer2 = getItemByTypeName('Computer', '_test_pc02');
            $computer3 = getItemByTypeName('Computer', '_test_pc03');
            $this->addDbEdge($computer1, $computer2);
            $edge = $this->addDbEdge($computer2, $computer3);
            $this->string($impact->getTabNameForItem($computer2))
                ->isIdenticalTo(ImpactModel::createTabEntry('Impact analysis', 2));
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $before = $factories->getValue();
            $this->string($impact->getTabNameForItem($computer2))
                ->isIdenticalTo(ImpactModel::createTabEntry('Impact analysis', 2));
            $connection->delete('glpi_impactrelations', ['id' => $edge]);
            $this->string($impact->getTabNameForItem($computer2))
                ->isIdenticalTo(ImpactModel::createTabEntry('Impact analysis', 1));
            $this->integer($factories->getValue() - $before)->isIdenticalTo(0);

            $connection->update('glpi_configs', ['value' => exportArrayToDB([])], ['context' => 'core', 'name' => ImpactModel::CONF_ENABLED]);
            $this->exception(static function () use ($impact, $computer2): void {
                $impact->getTabNameForItem($computer2);
            })->isInstanceOf(InvalidArgumentException::class);
            $connection->update('glpi_configs', ['value' => exportArrayToDB([Computer::class])], ['context' => 'core', 'name' => ImpactModel::CONF_ENABLED]);
            $this->string($impact->getTabNameForItem($computer2))
                ->isIdenticalTo(ImpactModel::createTabEntry('Impact analysis', 1));
            $this->string($impact->getTabNameForItem(new Computer()))->isIdenticalTo('Impact analysis');
        } finally {
            $_SESSION['glpishow_count_on_tabs'] = $old_session;
            if (array_key_exists(ImpactModel::CONF_ENABLED, $original)) {
                ConfigModel::setConfigurationValues('core', $original);
            } else {
                ConfigModel::deleteConfigurationValues('core', [ImpactModel::CONF_ENABLED]);
            }
        }
    }

    public function testImpactTabUsesOneConfigurationSnapshotOnSelectedCustomRoute(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $originalAdapter = $DB;
        $oldCount = $_SESSION['glpishow_count_on_tabs'];
        $allowed = $CFG_GLPI['impact_asset_types'];
        $original = ConfigModel::getConfigurationValues('core', [ImpactModel::CONF_ENABLED]);
        $connection = $DB->getDoctrineConnection();
        $this->mockGenerator()->orphanize('__construct');
        $item = new ImpactComputerProbe();
        $class = get_class($item);
        $this->setEntity('_test_root_entity', true);
        $entity = (int)Session::getActiveEntity();
        $source = $this->createItem(Computer::class, ['name' => '_impact_scope_source', 'entities_id' => $entity]);
        $target = $this->createItem(Computer::class, ['name' => '_impact_scope_target', 'entities_id' => $entity]);
        $item->fields = $target->fields;
        $targetId = (int)$target->getID();
        $probe = new ScalarReadProbe($connection);
        $this->mockGenerator()->orphanize('__construct');
        $adapter = new ImpactAdapterProbe();
        $this->calling($adapter)->getDoctrineConnection = $probe;
        $this->calling($adapter)->getProvider = $DB->getProvider();
        $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        try {
            $_SESSION['glpishow_count_on_tabs'] = true;
            $CFG_GLPI['impact_asset_types'][$class] = true;
            $json = exportArrayToDB([Computer::class, $class]);
            ConfigModel::setConfigurationValues('core', [ImpactModel::CONF_ENABLED => Toolbox::addslashes_deep($json)]);
            $stored = ConfigModel::getConfigurationValues('core', [ImpactModel::CONF_ENABLED]);
            $this->string($stored[ImpactModel::CONF_ENABLED])->isIdenticalTo($json);
            $this->array(importArrayFromDB($stored[ImpactModel::CONF_ENABLED]))->isIdenticalTo([Computer::class, $class]);
            $this->array(ImpactModel::getEnabledItemtypes())->isIdenticalTo([Computer::class, $class]);
            $connection->insert('glpi_impactrelations', [
                'itemtype_source' => Computer::class, 'items_id_source' => $source->getID(),
                'itemtype_impacted' => $class, 'items_id_impacted' => $targetId,
            ]);
            $atId = [];
            $this->calling($item)->getID = static function () use (&$atId, $factories, $probe, $connection, $targetId): int {
                $atId = [$factories->getValue(), count($probe->queries)];
                // A callback changes the next operation's admission; this count keeps its initial snapshot.
                $connection->update('glpi_configs', ['value' => exportArrayToDB([])], ['context' => 'core', 'name' => ImpactModel::CONF_ENABLED]);
                return $targetId;
            };
            $DB = $adapter;
            $before = $factories->getValue();
            $impact = new ImpactModel();
            $this->string($impact->getTabNameForItem($item))
                ->isIdenticalTo(ImpactModel::createTabEntry('Impact analysis', 1));
            $this->array($atId)->isIdenticalTo([$before + 2, 1], 'Selected Config and count managers precede getID; only Config SQL has run');
            $this->integer($factories->getValue() - $before)->isIdenticalTo(2);
            $this->array($probe->queries)->hasSize(2, 'One configuration query and one count use the supplied route');
            $this->exception(static function () use ($impact, $item): void {
                $impact->getTabNameForItem($item);
            })->isInstanceOf(InvalidArgumentException::class);
            $this->integer($factories->getValue() - $before)->isIdenticalTo(3, 'The next custom read owns a new independent manager');
            $this->array($probe->queries)->hasSize(3);
        } finally {
            $DB = $originalAdapter;
            $_SESSION['glpishow_count_on_tabs'] = $oldCount;
            $CFG_GLPI['impact_asset_types'] = $allowed;
            if (array_key_exists(ImpactModel::CONF_ENABLED, $original)) {
                ConfigModel::setConfigurationValues('core', array_map(static fn ($value) => Toolbox::addslashes_deep($value), $original));
            } else {
                ConfigModel::deleteConfigurationValues('core', [ImpactModel::CONF_ENABLED]);
            }
        }
    }

    public function testGetTabNameForItem_ITILObject()
    {
        $old_session = $_SESSION['glpishow_count_on_tabs'];
        $_SESSION['glpishow_count_on_tabs'] = true;

        $impact = new \Impact();
        $ticket_em = new Ticket();
        $item_ticket_em = new Item_Ticket();

        // Get computers
        $computer1 = getItemByTypeName('Computer', '_test_pc01');
        $computer2 = getItemByTypeName('Computer', '_test_pc02');
        $computer3 = getItemByTypeName('Computer', '_test_pc03');

        // Create an impact graph
        $this->addDbEdge($computer1, $computer2);
        $this->addDbEdge($computer2, $computer3);

        // Create a ticket and link it to the computer
        $ticket_id = $ticket_em->add(['name' => "test", 'content' => "test"]);
        $item_ticket_em->add([
           'itemtype'   => "Computer",
           'items_id'   => $computer2->fields['id'],
           'tickets_id' => $ticket_id,
        ]);

        // Get the actual ticket
        $ticket = new Ticket();
        $ticket->getFromDB($ticket_id);

        $tab_name = $impact->getTabNameForItem($ticket);
        $_SESSION['glpishow_count_on_tabs'] = $old_session;

        $this->string($tab_name)->isEqualTo("Impact analysis");
    }

    public function testBuildGraph_empty()
    {
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $graph = \Impact::buildGraph($computer);

        $this->array($graph)->hasKeys(["nodes", "edges"]);

        // Nodes should contain only _test_pc01
        $id = $computer->fields['id'];
        $this->array($graph["nodes"])->hasSize(1);
        $this->string($graph["nodes"]["Computer::$id"]['label'])->isEqualTo("_test_pc01");

        // Edges should be empty
        $this->array($graph["edges"])->hasSize(0);
    }

    public function testBuildGraph_complex()
    {
        $computer1 = getItemByTypeName('Computer', '_test_pc01');
        $computer2 = getItemByTypeName('Computer', '_test_pc02');
        $computer3 = getItemByTypeName('Computer', '_test_pc03');
        $computer4 = getItemByTypeName('Computer', '_test_pc11');
        $computer5 = getItemByTypeName('Computer', '_test_pc12');
        $computer6 = getItemByTypeName('Computer', '_test_pc13');

        // Set compounds
        $compound01_id = $this->addDbCompound("_test_compound01", "#000011");
        $compound02_id = $this->addDbCompound("_test_compound02", "#110000");

        // Set impact items
        $this->addDbNode($computer1);
        $this->addDbNode($computer2, $compound01_id);
        $this->addDbNode($computer3, $compound01_id);
        $this->addDbNode($computer4, $compound02_id);
        $this->addDbNode($computer5, $compound02_id);
        $this->addDbNode($computer6, $compound02_id);

        // Set relations
        $this->addDbEdge($computer1, $computer2);
        $this->addDbEdge($computer2, $computer3);
        $this->addDbEdge($computer3, $computer4);
        $this->addDbEdge($computer4, $computer5);
        $this->addDbEdge($computer2, $computer6);
        $this->addDbEdge($computer6, $computer2);

        // Build graph from pc02
        $computer = getItemByTypeName('Computer', '_test_pc02');
        $this->login();
        $objects = [];
        foreach ([
            ['incidents', Ticket::class, Item_Ticket::class, Ticket::INCIDENT_TYPE],
            ['requests', Ticket::class, Item_Ticket::class, Ticket::DEMAND_TYPE],
            ['changes', Change::class, Change_Item::class, null],
            ['problems', Problem::class, Item_Problem::class, null],
        ] as [$bucket, $class, $linkClass, $type]) {
            $object = new $class();
            $input = ['name' => 'Impact ' . $bucket . ' ' . $this->getUniqueString(),
                'content' => 'Linked graph object', 'entities_id' => (int)$computer->fields['entities_id']];
            if ($type !== null) {
                $input['type'] = $type;
            }
            $this->integer((int)$object->add($input))->isGreaterThan(0);
            $link = new $linkClass();
            $this->integer((int)$link->add(['itemtype' => Computer::class, 'items_id' => $computer->getID(),
                $class::getForeignKeyField() => $object->getID()]))->isGreaterThan(0);
            $objects[$bucket] = $object;
        }
        $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $beforeFactories = $factories->getValue();
        $graph = ImpactModel::buildGraph($computer);
        $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0, 'A populated graph reuses the existing request manager');
        $nodeId = ImpactModel::getNodeID($computer);
        foreach ($objects as $bucket => $object) {
            $this->array(array_column($graph['nodes'][$nodeId]['ITILObjects'][$bucket], 'id'))
                ->contains((int)$object->getID());
        }
        $this->array(ImpactModel::buildGraph($computer))->isIdenticalTo($graph);
        $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0);

        $connection = $GLOBALS['DB']->getDoctrineConnection();
        $activeQueryPlans = null;
        Orm::read($GLOBALS['DB'], function (EntityManager $manager) use ($computer, $objects, $graph, $nodeId, &$activeQueryPlans): void {
            $this->boolean($manager->getConnection()->ownsApplicationEntityManager($manager))->isTrue();
            $cache = $manager->getConfiguration()->getQueryCache();
            $this->object($cache)->isInstanceOf(ArrayAdapter::class);
            $cache->clear();
            foreach ([
                ['incidents', Entity\ItemTicket::class, Ticket::INCIDENT_TYPE],
                ['requests', Entity\ItemTicket::class, Ticket::DEMAND_TYPE],
                ['changes', Entity\ChangeItem::class, null],
                ['problems', Entity\ItemProblem::class, null],
            ] as [$bucket, $link, $type]) {
                $object = $objects[$bucket];
                $finished = array_merge($object->getSolvedStatusArray(), $object->getClosedStatusArray());
                $read = $type === null
                    ? fn (): array => (new ITILAssetRepository($manager))->activeForLink($link, Computer::class, (int)$computer->getID(), $finished)
                    : fn (): array => (new TicketAssetRepository($manager))->active(Computer::class, (int)$computer->getID(), $finished, $type);
                $rows = $read();
                $this->array($rows)->isIdenticalTo($graph['nodes'][$nodeId]['ITILObjects'][$bucket]);
                $byId = array_column($rows, null, 'id');
                $this->array($byId[(int)$object->getID()])->isIdenticalTo([
                    'id' => (int)$object->getID(), 'name' => $object->fields['name'], 'priority' => (int)$object->fields['priority'],
                ]);
                $original = ['is_deleted' => $object->fields['is_deleted'], 'status' => $object->fields['status']];
                foreach ([['is_deleted' => 1], ['status' => $finished[0]]] as $hidden) {
                    try {
                        $manager->getConnection()->update($object::getTable(), $hidden, ['id' => $object->getID()]);
                        $this->array(array_column($read(), 'id'))->notContains((int)$object->getID());
                    } finally {
                        $manager->getConnection()->update($object::getTable(), $original, ['id' => $object->getID()]);
                    }
                }
                $this->array($read())->isIdenticalTo($graph['nodes'][$nodeId]['ITILObjects'][$bucket]);
            }
            $activeQueryPlans = count($cache->getValues());
        });
        $connection->update('glpi_tickets', ['name' => 'Fresh graph incident'], ['id' => $objects['incidents']->getID()]);
        $fresh = ImpactModel::buildGraph($computer);
        $this->array(array_column($fresh['nodes'][$nodeId]['ITILObjects']['incidents'], 'name', 'id'))
            ->hasKey((int)$objects['incidents']->getID());
        $names = array_column($fresh['nodes'][$nodeId]['ITILObjects']['incidents'], 'name', 'id');
        $this->string($names[(int)$objects['incidents']->getID()])->isIdenticalTo('Fresh graph incident');
        $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0);
        $found = ImpactModel::searchAsset(Computer::class, [], $computer->fields['name']);
        $this->array(array_map('intval', array_column($found['items'], 'id')))->contains((int)$computer->getID());
        $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(0);
        Orm::withConnection($connection, function (EntityManager $outer) use ($computer, $factories): void {
            $pending = $outer->find(User::class, (int)Session::getLoginUserID());
            $pending->priority_5 = '#123789';
            $beforeNestedFactories = $factories->getValue();
            $computer->getITILTickets(true);
            $this->integer($factories->getValue() - $beforeNestedFactories)->isIdenticalTo(4, 'Each nested ITIL read owns a separate manager');
            $this->boolean($outer->contains($pending))->isTrue();
            $this->string($pending->priority_5)->isIdenticalTo('#123789');
        });

        $events = new EventManager();
        $observer = new class () {
            public int $clears = 0;
            public array $loaded = [];
            public ?string $changeParent = null;
            public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
            {
                $metadata = $event->getClassMetadata();
                $this->loaded[] = $metadata->name;
                if ($metadata->name === Entity\ChangeItem::class) {
                    if ($this->changeParent === 'redirect') {
                        $association = $metadata->associationMappings['changes'];
                        $definition = $association->toArray();
                        $definition['targetEntity'] = Entity\Problem::class;
                        $metadata->associationMappings['changes'] = $association::fromMappingArray($definition);
                    } elseif ($this->changeParent === 'remove') {
                        unset($metadata->associationMappings['changes']);
                    }
                }
            }
            public function onClear(): void
            {
                ++$this->clears;
            }
        };
        $events->addEventListener(['onClear', 'loadClassMetadata'], $observer);
        $probe = new class ($connection) extends ScalarReadProbe {
            public EventManager $events;
            public function getEventManager(): EventManager
            {
                return $this->events;
            }
        };
        $probe->events = $events;
        $originalAdapter = $GLOBALS['DB'];
        $this->mockGenerator()->orphanize('__construct');
        $adapter = new ImpactAdapterProbe();
        $getters = 0;
        $this->calling($adapter)->getDoctrineConnection = static function () use ($probe, &$getters) {
            ++$getters;
            return $probe;
        };
        $this->calling($adapter)->getProvider = $originalAdapter->getProvider();
        try {
            $GLOBALS['DB'] = $adapter;
            $beforeFactories = $factories->getValue();
            $this->array($computer->getITILTickets(true))->isIdenticalTo($fresh['nodes'][$nodeId]['ITILObjects']);
            $this->integer($factories->getValue() - $beforeFactories)->isIdenticalTo(4);
            $this->integer($getters)->isIdenticalTo(4);
            $this->array($probe->queries)->hasSize(4);
            $this->integer($observer->clears)->isIdenticalTo(0);
            $this->array($observer->loaded)->contains(Entity\ChangeItem::class)->contains(Entity\ItemProblem::class);
            $this->array($observer->loaded)->notContains(Entity\TicketTask::class)
                ->notContains(Entity\ChangeTask::class)->notContains(Entity\ProblemTask::class);

            $observer->changeParent = 'redirect';
            $probe->queries = [];
            $objects['changes']->getActiveChangesForItem(Computer::class, $computer->getID());
            $this->array($probe->queries)->hasSize(1);
            $this->string($probe->queries[0]['sql'])->contains('glpi_problems');

            $observer->changeParent = 'remove';
            $probe->queries = [];
            $this->exception(fn () => $objects['changes']->getActiveChangesForItem(Computer::class, $computer->getID()))
                ->isInstanceOf(LogicException::class)
                ->hasMessage('Expected one ITIL asset parent association: ' . Entity\ChangeItem::class);
            $this->array($probe->queries)->isEmpty();
            $this->integer($observer->clears)->isIdenticalTo(0);
        } finally {
            $GLOBALS['DB'] = $originalAdapter;
        }
        $this->array($graph)->hasKeys(["nodes", "edges"]);

        // Nodes should contain 8 elements (6 nodes + 2 compounds)
        $this->array($graph["nodes"])->hasSize(8);
        $nodes = array_filter($graph["nodes"], function ($elem) {
            return !isset($elem['color']);
        });
        $this->array($nodes)->hasSize(6);
        $compounds = array_filter($graph["nodes"], function ($elem) {
            return isset($elem['color']);
        });
        $this->array($compounds)->hasSize(2);

        // Edges should contain 6 elements (3 forward, 1 backward, 2 both)
        $this->array($graph["edges"])->hasSize(6);
        $backward = array_filter($graph["edges"], function ($elem) {
            return $elem["flag"] == \Impact::DIRECTION_BACKWARD;
        });
        $this->array($backward)->hasSize(1);
        $forward = array_filter($graph["edges"], function ($elem) {
            return $elem["flag"] == \Impact::DIRECTION_FORWARD;
        });
        $this->array($forward)->hasSize(3);
        $both = array_filter($graph["edges"], function ($elem) {
            return $elem["flag"] == (\Impact::DIRECTION_FORWARD | \Impact::DIRECTION_BACKWARD);
        });
        $this->array($both)->hasSize(2);
        $this->integer($activeQueryPlans)->isIdenticalTo(0, 'Canonical active lists execute without compiling DQL');
    }

    public function testClean()
    {
        global $DB;

        $compound_em = new ImpactCompound();

        $computer1 = getItemByTypeName('Computer', '_test_pc01');
        $computer2 = getItemByTypeName('Computer', '_test_pc02');
        $computer3 = getItemByTypeName('Computer', '_test_pc03');
        $computer4 = getItemByTypeName('Computer', '_test_pc11');
        $computer5 = getItemByTypeName('Computer', '_test_pc12');
        $computer6 = getItemByTypeName('Computer', '_test_pc13');

        // Set compounds
        $compound01_id = $this->addDbCompound("_test_compound01", "#000011");
        $compound02_id = $this->addDbCompound("_test_compound02", "#110000");

        // Set impact items
        $this->addDbNode($computer1);
        $this->addDbNode($computer2, $compound01_id);
        $this->addDbNode($computer3, $compound01_id);
        $this->addDbNode($computer4, $compound02_id);
        $this->addDbNode($computer5, $compound02_id);
        $this->addDbNode($computer6, $compound02_id);

        // Set relations
        $this->addDbEdge($computer1, $computer2);
        $this->addDbEdge($computer2, $computer3);
        $this->addDbEdge($computer3, $computer4);
        $this->addDbEdge($computer4, $computer5);
        $this->addDbEdge($computer2, $computer6);
        $this->addDbEdge($computer6, $computer2);

        // Test queries to evaluate before and after clean
        $relations_to_computer2_query = [
           'FROM'   => \ImpactRelation::getTable(),
           'WHERE' => [
              'OR' => [
                 [
                    'itemtype_source' => get_class($computer2),
                    'items_id_source' => $computer2->fields['id']
                 ],
                 [
                    'itemtype_impacted' => get_class($computer2),
                    'items_id_impacted' => $computer2->fields['id']
                 ],
              ]
           ]
        ];
        $impact_item_computer2_query = [
           'FROM'   => \ImpactItem::getTable(),
           'WHERE'  => [
              'itemtype' => get_class($computer2),
              'items_id' => $computer2->fields['id'],
           ]
        ];
        $compound01_members_query = [
           'FROM' => \ImpactItem::getTable(),
           'WHERE' => ["parent_id" => $compound01_id]
        ];

        // Before deletion
        $this->integer(count($DB->request($relations_to_computer2_query)))
           ->isEqualTo(4);
        $this->integer(count($DB->request($impact_item_computer2_query)))
           ->isEqualTo(1);
        $this->integer(count($DB->request($compound01_members_query)))
           ->isEqualTo(2);
        $this->boolean($compound_em->getFromDB($compound01_id))
           ->isEqualTo(true);

        // Delete pc02
        $computer2->delete($computer2->fields, true);

        // After deletion
        $this->integer(count($DB->request($relations_to_computer2_query)))
           ->isEqualTo(0);
        $this->integer(count($DB->request($impact_item_computer2_query)))
           ->isEqualTo(0);
        $this->integer(count($DB->request($compound01_members_query)))
           ->isEqualTo(0);
        $this->boolean($compound_em->getFromDB($compound01_id))
           ->isEqualTo(false);
    }

    private $nodes = [
       ['id' => "A"],
       ['id' => "B"],
       ['id' => "C"],
       ['id' => "D"],
       ['id' => "E"],
       ['id' => "F"],
       ['id' => "G"],
    ];

    private $forward = [
       ['A', 'B'],
       ['B', 'E'],
       ['E', 'G'],
    ];

    private $backward = [
       ['F', 'C'],
    ];

    private $both = [
       ['A', 'D'],
       ['D', 'C'],
       ['C', 'A'],
    ];

    private $graph = [
       'nodes' => [],
       'edges' => []
    ];

    public function bfsProvider()
    {
        return [
           [
              'a'         => ['id' => "A"],
              'b'         => ['id' => "B"],
              'direction' => \Impact::DIRECTION_FORWARD,
              'result'    => [
                 $this->graph['nodes']['A'],
                 $this->graph['nodes']['B'],
              ],
           ],
           [
              'a'         => ['id' => "A"],
              'b'         => ['id' => "E"],
              'direction' => \Impact::DIRECTION_FORWARD,
              'result'    => [
                 $this->graph['nodes']['A'],
                 $this->graph['nodes']['B'],
                 $this->graph['nodes']['E'],
              ],
           ],
           [
              'a'         => ['id' => "A"],
              'b'         => ['id' => "G"],
              'direction' => \Impact::DIRECTION_FORWARD,
              'result'    => [
                 $this->graph['nodes']['A'],
                 $this->graph['nodes']['B'],
                 $this->graph['nodes']['E'],
                 $this->graph['nodes']['G'],
              ],
           ],
           [
              'a'         => ['id' => "A"],
              'b'         => ['id' => "D"],
              'direction' => \Impact::DIRECTION_FORWARD,
              'result'    => [
                 $this->graph['nodes']['A'],
                 $this->graph['nodes']['D'],
              ],
           ],
           [
              'a'         => ['id' => "A"],
              'b'         => ['id' => "C"],
              'direction' => \Impact::DIRECTION_FORWARD,
              'result'    => [
                 $this->graph['nodes']['A'],
                 $this->graph['nodes']['D'],
                 $this->graph['nodes']['C'],
              ],
           ],
           [
              'a'         => ['id' => "A"],
              'b'         => ['id' => "D"],
              'direction' => \Impact::DIRECTION_BACKWARD,
              'result'    => [
                 $this->graph['nodes']['D'],
                 $this->graph['nodes']['C'],
                 $this->graph['nodes']['A'],
              ],
           ],
           [
              'a'         => ['id' => "A"],
              'b'         => ['id' => "C"],
              'direction' => \Impact::DIRECTION_BACKWARD,
              'result'    => [
                 $this->graph['nodes']['C'],
                 $this->graph['nodes']['A'],
              ],
           ],
           [
              'a'         => ['id' => "A"],
              'b'         => ['id' => "F"],
              'direction' => \Impact::DIRECTION_BACKWARD,
              'result'    => [
                 $this->graph['nodes']['F'],
                 $this->graph['nodes']['C'],
                 $this->graph['nodes']['A'],
              ],
           ],
        ];
    }

    /**
     * @dataProvider bfsProvider
     */
    public function testBfs($a, $b, $direction, $result)
    {
        $path = \Impact::bfs($this->graph, $a, $b, $direction);
        $this->array($path);

        for ($i = 0; $i < count($path); $i++) {
            $this->string($path[$i]['id'])->isEqualTo($result[$i]['id']);
        }
    }

    public function testFilterGraph()
    {
        $forward = \Impact::filterGraph($this->graph, \Impact::DIRECTION_FORWARD);
        $this->array($forward)->hasKey('nodes')->hasKey('edges');

        foreach ($forward['edges'] as $edge) {
            $this->integer(\Impact::DIRECTION_FORWARD & $edge['flag'])->isEqualTo(\Impact::DIRECTION_FORWARD);
        }

        $backward = \Impact::filterGraph($this->graph, \Impact::DIRECTION_BACKWARD);
        $this->array($forward)->hasKey('nodes')->hasKey('edges');

        foreach ($backward['edges'] as $edge) {
            $this->integer(\Impact::DIRECTION_BACKWARD & $edge['flag'])->isEqualTo(\Impact::DIRECTION_BACKWARD);
        }
    }
}
