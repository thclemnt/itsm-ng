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

use DateTime;
use DbTestCase;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use HTMLTableMain;
use InvalidArgumentException;
use NetworkPort as LegacyNetworkPort;
use NetworkPortAggregate;
use NetworkPortAlias;
use NetworkPortEthernet;
use NetworkPortInstantiation;
use ReflectionProperty;
use NetworkPort_Vlan;
use itsmng\Database\Entity\NetworkPort as NetworkPortEntity;
use itsmng\Database\Entity\NetworkPortAggregate as NetworkPortAggregateEntity;
use itsmng\Database\Entity\Vlan as VlanEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\NetworkPortAggregateRepository;
use itsmng\Database\Repository\NetworkPortVlanRepository;
use itsmng\Domain\VlanMembershipService;

/* Test for inc/networkport.class.php */

class NetworkPort extends DbTestCase
{
    public function testAddSimpleNetworkPort()
    {
        $this->login();

        $computer1 = getItemByTypeName('Computer', '_test_pc01');
        $networkport = new LegacyNetworkPort();

        // Be sure added
        $nb_log = (int)countElementsInTable('glpi_logs');
        $new_id = $networkport->add([
           'items_id'           => $computer1->getID(),
           'itemtype'           => 'Computer',
           'entities_id'        => $computer1->fields['entities_id'],
           'is_recursive'       => 0,
           'logical_number'     => 1,
           'mac'                => '00:24:81:eb:c6:d0',
           'instantiation_type' => 'NetworkPortEthernet',
           'name'               => 'eth1',
        ]);
        $this->integer((int)$new_id)->isGreaterThan(0);
        $this->integer((int)countElementsInTable('glpi_logs'))->isGreaterThan($nb_log);

        // check data in db
        $all_netports = getAllDataFromTable('glpi_networkports', ['ORDER' => 'id']);
        $current_networkport = end($all_netports);
        unset($current_networkport['id']);
        unset($current_networkport['date_mod']);
        unset($current_networkport['date_creation']);
        $expected = [
            'items_id'           => $computer1->getID(),
            'itemtype'           => 'Computer',
            'entities_id'        => $computer1->fields['entities_id'],
            'is_recursive'       => 0,
            'logical_number'     => 1,
            'name'               => 'eth1',
            'instantiation_type' => 'NetworkPortEthernet',
            'mac'                => '00:24:81:eb:c6:d0',
            'comment'            => null,
            'is_deleted'         => 0,
            'is_dynamic'         => 0,
        ];
        $this->array($current_networkport)->isIdenticalTo($expected);

        $all_netportethernets = getAllDataFromTable('glpi_networkportethernets', ['ORDER' => 'id']);
        $networkportethernet = end($all_netportethernets);
        $this->boolean($networkportethernet)->isFalse();

        // be sure added and have no logs
        $nb_log = (int)countElementsInTable('glpi_logs');
        $new_id = $networkport->add([
           'items_id'           => $computer1->getID(),
           'itemtype'           => 'Computer',
           'entities_id'        => $computer1->fields['entities_id'],
           'logical_number'     => 2,
           'mac'                => '00:24:81:eb:c6:d1',
           'instantiation_type' => 'NetworkPortEthernet',
        ], [], false);
        $this->integer((int)$new_id)->isGreaterThan(0);
        $this->integer((int)countElementsInTable('glpi_logs'))->isIdenticalTo($nb_log);
    }

    public function testAddCompleteNetworkPort()
    {
        $this->login();

        $computer1 = getItemByTypeName('Computer', '_test_pc01');

        // Do some installations
        $networkport = new LegacyNetworkPort();

        // Be sure added
        $nb_log = (int)countElementsInTable('glpi_logs');
        $new_id = $networkport->add([
           'items_id'                    => $computer1->getID(),
           'itemtype'                    => 'Computer',
           'entities_id'                 => $computer1->fields['entities_id'],
           'is_recursive'                => 0,
           'logical_number'              => 3,
           'mac'                         => '00:24:81:eb:c6:d2',
           'instantiation_type'          => 'NetworkPortEthernet',
           'name'                        => 'em3',
           'comment'                     => 'Comment me!',
           'netpoints_id'                => 0,
           'items_devicenetworkcards_id' => 0,
           'type'                        => 'T',
           'speed'                       => 1000,
           'speed_other_value'           => '',
           'NetworkName_name'            => 'test1',
           'NetworkName_comment'         => 'test1 comment',
           'NetworkName_fqdns_id'        => 0,
           'NetworkName__ipaddresses'    => ['-1' => '192.168.20.1'],
           '_create_children'            => true // automatically add instancation, networkname and ipadresses
        ]);
        $this->integer($new_id)->isGreaterThan(0);
        $this->integer((int)countElementsInTable('glpi_logs'))->isGreaterThan($nb_log);

        // check data in db
        // 1 -> NetworkPortEthernet
        $all_netportethernets = getAllDataFromTable('glpi_networkportethernets', ['ORDER' => 'id']);
        $networkportethernet = end($all_netportethernets);
        unset($networkportethernet['id']);
        unset($networkportethernet['date_mod']);
        unset($networkportethernet['date_creation']);
        $expected = [
            'networkports_id'             => $new_id,
            'items_devicenetworkcards_id' => null,
            'netpoints_id'                => null,
            'type'                        => 'T',
            'speed'                       => 1000,
        ];
        $this->array($networkportethernet)->isIdenticalTo($expected);

        // 2 -> NetworkName
        $all_networknames = getAllDataFromTable('glpi_networknames', ['ORDER' => 'id']);
        $networkname = end($all_networknames);
        $networknames_id = $networkname['id'];
        unset($networkname['id']);
        unset($networkname['date_mod']);
        unset($networkname['date_creation']);
        $expected = [
            'entities_id' => $computer1->fields['entities_id'],
            'items_id'    => $new_id,
            'itemtype'    => 'NetworkPort',
            'name'        => 'test1',
            'comment'     => 'test1 comment',
            'fqdns_id'    => null,
            'is_deleted'  => 0,
            'is_dynamic'  => 0,
        ];
        $this->array($networkname)->isIdenticalTo($expected);

        // 3 -> IPAddress
        $all_ipadresses = getAllDataFromTable('glpi_ipaddresses', ['ORDER' => 'id']);
        $ipadress = end($all_ipadresses);
        unset($ipadress['id']);
        unset($ipadress['date_mod']);
        unset($ipadress['date_creation']);
        $expected = [
            'entities_id'  => $computer1->fields['entities_id'],
            'items_id'     => $networknames_id,
            'itemtype'     => 'NetworkName',
            'version'      => 4,
            'name'         => '192.168.20.1',
            'binary_0'     => 0,
            'binary_1'     => 0,
            'binary_2'     => 65535,
            'binary_3'     => 3232240641,
            'is_deleted'   => 0,
            'is_dynamic'   => 0,
            'mainitems_id' => $computer1->getID(),
            'mainitemtype' => 'Computer',
        ];
        $this->array($ipadress)->isIdenticalTo($expected);

        // be sure added and have no logs
        $nb_log = (int)countElementsInTable('glpi_logs');
        $new_id = $networkport->add([
           'items_id'                    => $computer1->getID(),
           'itemtype'                    => 'Computer',
           'entities_id'                 => $computer1->fields['entities_id'],
           'is_recursive'                => 0,
           'logical_number'              => 4,
           'mac'                         => '00:24:81:eb:c6:d4',
           'instantiation_type'          => 'NetworkPortEthernet',
           'name'                        => 'em4',
           'comment'                     => 'Comment me!',
           'netpoints_id'                => 0,
           'items_devicenetworkcards_id' => 0,
           'type'                        => 'T',
           'speed'                       => 1000,
           'speed_other_value'           => '',
           'NetworkName_name'            => 'test2',
           'NetworkName_fqdns_id'        => 0,
           'NetworkName__ipaddresses'    => ['-1' => '192.168.20.2']
        ], [], false);
        $this->integer((int)$new_id)->isGreaterThan(0);
        $this->integer((int)countElementsInTable('glpi_logs'))->isIdenticalTo($nb_log);
    }

    public function testClone()
    {
        $this->login();

        $date = date('Y-m-d H:i:s');
        $_SESSION['glpi_currenttime'] = $date;

        $computer1 = getItemByTypeName('Computer', '_test_pc01');

        // Do some installations
        $networkport = new LegacyNetworkPort();

        // Be sure added
        $nb_log = (int)countElementsInTable('glpi_logs');
        $new_id = $networkport->add([
           'items_id'                    => $computer1->getID(),
           'itemtype'                    => 'Computer',
           'entities_id'                 => $computer1->fields['entities_id'],
           'is_recursive'                => 0,
           'logical_number'              => 3,
           'mac'                         => '00:24:81:eb:c6:d2',
           'instantiation_type'          => 'NetworkPortEthernet',
           'name'                        => 'em3',
           'comment'                     => 'Comment me!',
           'netpoints_id'                => 0,
           'items_devicenetworkcards_id' => 0,
           'type'                        => 'T',
           'speed'                       => 1000,
           'speed_other_value'           => '',
           'NetworkName_name'            => 'test1',
           'NetworkName_comment'         => 'test1 comment',
           'NetworkName_fqdns_id'        => 0,
           'NetworkName__ipaddresses'    => ['-1' => '192.168.20.1'],
           '_create_children'            => true // automatically add instancation, networkname and ipadresses
        ]);
        $this->integer($new_id)->isGreaterThan(0);
        $this->integer((int)countElementsInTable('glpi_logs'))->isGreaterThan($nb_log);

        // Test item cloning
        $added = $networkport->clone();
        $this->integer((int)$added)->isGreaterThan(0);

        $clonedNetworkport = new LegacyNetworkPort();
        $this->boolean($clonedNetworkport->getFromDB($added))->isTrue();

        $fields = $networkport->fields;

        // Check the networkport values. Id and dates must be different, everything else must be equal
        foreach ($fields as $k => $v) {
            switch ($k) {
                case 'id':
                    $this->variable($clonedNetworkport->getField($k))->isNotEqualTo($networkport->getField($k));
                    break;
                case 'date_mod':
                case 'date_creation':
                    $dateClone = new DateTime($clonedNetworkport->getField($k));
                    $expectedDate = new DateTime($date);
                    $this->dateTime($dateClone)->isEqualTo($expectedDate);
                    break;
                case 'name':
                    $this->variable($clonedNetworkport->getField($k))->isEqualTo("{$networkport->getField($k)} (copy)");
                    break;
                default:
                    $this->variable($clonedNetworkport->getField($k))->isEqualTo($networkport->getField($k));
            }
        }

        $instantiation = $networkport->getInstantiation();
        $clonedInstantiation = $clonedNetworkport->getInstantiation();
        $instantiationFields = $instantiation->fields;

        // Check the networkport instantiation values. Id, networkports_id and dates must be different, everything else must be equal
        foreach ($instantiationFields as $k => $v) {
            switch ($k) {
                case 'id':
                    $this->variable($clonedInstantiation->getField($k))->isNotEqualTo($instantiation->getField($k));
                    break;
                case 'networkports_id':
                    $this->variable($clonedInstantiation->getField($k))->isNotEqualTo($instantiation->getField($k));
                    $this->variable($clonedInstantiation->getField($k))->isEqualTo($clonedNetworkport->getID());
                    break;
                case 'date_mod':
                case 'date_creation':
                    $dateClone = new DateTime($clonedInstantiation->getField($k));
                    $expectedDate = new DateTime($date);
                    $this->dateTime($dateClone)->isEqualTo($expectedDate);
                    break;
                default:
                    $this->variable($clonedInstantiation->getField($k))->isEqualTo($instantiation->getField($k));
            }
        }
    }

    public function testAliasCopiesMacFromOriginPort()
    {
        $this->login();

        $computer = getItemByTypeName('Computer', '_test_pc01');
        $networkport = new LegacyNetworkPort();

        $origin_port_id = $networkport->add([
           'items_id'           => $computer->getID(),
           'itemtype'           => 'Computer',
           'entities_id'        => $computer->fields['entities_id'],
           'is_recursive'       => 0,
           'logical_number'     => 10,
           'mac'                => '00:24:81:eb:c7:10',
           'instantiation_type' => 'NetworkPortEthernet',
           'name'               => 'origin-port',
        ]);
        $this->integer($origin_port_id)->isGreaterThan(0);

        $alias_port_id = $networkport->add([
           'items_id'           => $computer->getID(),
           'itemtype'           => 'Computer',
           'entities_id'        => $computer->fields['entities_id'],
           'is_recursive'       => 0,
           'logical_number'     => 11,
           'instantiation_type' => 'NetworkPortAlias',
           'name'               => 'alias-port',
        ]);
        $this->integer($alias_port_id)->isGreaterThan(0);

        $alias = new NetworkPortAlias();
        $alias_id = $alias->add([
           'networkports_id'       => $alias_port_id,
           'networkports_id_alias' => $origin_port_id,
        ]);
        $this->integer($alias_id)->isGreaterThan(0);

        // Check that the alias port's MAC is updated to origin port's MAC
        $aliasNetworkPort = new LegacyNetworkPort();
        $this->boolean($aliasNetworkPort->getFromDB($alias_port_id))->isTrue();
        $this->string($aliasNetworkPort->fields['mac'])->isEqualTo('00:24:81:eb:c7:10');

        // Both virtual-port labels and origin choices must reflect scalar writes between renders.
        $origin = new LegacyNetworkPort();
        $this->boolean($origin->getFromDB($origin_port_id))->isTrue();
        $render = static function () use ($origin, $computer, $alias): string {
            $table = new HTMLTableMain();
            $header = $table->addHeader('Instantiation', 'Ports');
            $group = $table->createGroup('ports', 'Ports');
            $group->addHeader('VirtualPorts', 'Virtual ports', $header);
            (new NetworkPortInstantiation())->getInstantiationHTMLTable($origin, $group->createRow(), null, [
                'display_options' => ['virtual_ports' => true, 'vlans' => false, 'internet' => false, 'mac' => false],
            ]);
            ob_start();
            try {
                $table->display([]);
                $alias->showNetworkPortSelector([$computer], 'NetworkPortAlias');
                return ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        $first = $render();
        $this->string($first)->contains('alias-port')->contains('origin-port')->contains('00:24:81:eb:c7:10');
        $connection = $GLOBALS['DB']->getDoctrineConnection();
        $connection->update('glpi_networkports', ['name' => 'fresh-virtual-port'], ['id' => $alias_port_id]);
        $connection->update('glpi_networkports', ['name' => 'fresh-origin-port', 'mac' => '00:24:81:eb:c7:20'], ['id' => $origin_port_id]);
        $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $before = $factories->getValue();
        $fresh = $render();
        $this->string($fresh)->contains('fresh-virtual-port')->contains('fresh-origin-port')->contains('00:24:81:eb:c7:20');
        $this->string($fresh)->notContains('alias-port');
        // Keep the genuine old-runtime allocation failure after every positive rendering check.
        $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
    }

    public function testAggregateStoresPortList()
    {
        global $DB;
        $this->login();

        $networkequipment = getItemByTypeName('NetworkEquipment', '_test_networkequipment_1');
        $networkport = new LegacyNetworkPort();

        $port1 = (int)$networkport->add([
           'name'         => 'agg-if1',
           'instantiation_type' => 'NetworkPortEthernet',
           '_create_children' => true,
           'items_id'     => $networkequipment->getID(),
           'itemtype'     => 'NetworkEquipment',
           'entities_id'  => $networkequipment->fields['entities_id'],
        ]);
        $port2 = (int)$networkport->add([
           'name'         => 'agg-if2',
           'instantiation_type' => 'NetworkPortWifi',
           '_create_children' => true,
           'items_id'     => $networkequipment->getID(),
           'itemtype'     => 'NetworkEquipment',
           'entities_id'  => $networkequipment->fields['entities_id'],
        ]);
        $port3 = (int)$networkport->add([
           'name'         => 'agg-if3',
           'instantiation_type' => 'NetworkPortEthernet',
           '_create_children' => true,
           'items_id'     => $networkequipment->getID(),
           'itemtype'     => 'NetworkEquipment',
           'entities_id'  => $networkequipment->fields['entities_id'],
        ]);
        $agg_parent_port = (int)$networkport->add([
           'name'         => 'agg-parent',
           'items_id'     => $networkequipment->getID(),
           'itemtype'     => 'NetworkEquipment',
           'entities_id'  => $networkequipment->fields['entities_id'],
        ]);

        $this->integer($port1)->isGreaterThan(0);
        $this->integer($port2)->isGreaterThan(0);
        $this->integer($port3)->isGreaterThan(0);
        $this->integer($agg_parent_port)->isGreaterThan(0);

        $aggregate = new NetworkPortAggregate();
        $aggregate_id = $aggregate->add([
           'networkports_id'      => $agg_parent_port,
           'networkports_id_list' => [$port1, $port2],
        ]);
        $this->integer($aggregate_id)->isGreaterThan(0);
        $this->array(importArrayFromDB($aggregate->fields['networkports_id_list']))
           ->isIdenticalTo([$port1, $port2]);

        $this->boolean($aggregate->update([
           'id'                   => $aggregate_id,
           'networkports_id'      => $agg_parent_port,
           'networkports_id_list' => [$port2, $port3],
        ]))->isTrue();
        $this->array(importArrayFromDB($aggregate->fields['networkports_id_list']))
           ->isIdenticalTo([$port2, $port3]);

        $this->boolean($networkport->getFromDB($agg_parent_port))->isTrue();
        $this->boolean($aggregate->getFromDB($agg_parent_port))->isTrue();
        $form = $aggregate->showInstantiationForm($networkport, [], [$networkequipment]);
        $originInput = $form[$aggregate->getTypeName()]['inputs'][__('Origin port')];
        $this->array($originInput['values'])->isIdenticalTo([$port2, $port3]);
        $this->array($originInput['options'])->hasKey($port1)->hasKey($port2)->hasKey($port3);
        $previousName = $originInput['options'][$port2];
        $this->boolean($DB->update('glpi_networkports', ['name' => 'Current aggregate option'], ['id' => $port2]))->isTrue();
        $this->boolean($DB->delete('glpi_networkportaggregateorigins', [
            'networkportaggregates_id' => $aggregate->fields['id'], 'networkports_id' => $port2,
        ]))->isTrue();
        $this->boolean($aggregate->getFromDB($agg_parent_port))->isTrue();
        $form = $aggregate->showInstantiationForm($networkport, [], [$networkequipment]);
        $currentInput = $form[$aggregate->getTypeName()]['inputs'][__('Origin port')];
        $this->array($currentInput['values'])->isIdenticalTo([$port3]);
        $this->string($currentInput['options'][$port2])->contains('Current aggregate option');
        $this->string($originInput['options'][$port2])->isIdenticalTo($previousName);

        $foreign = Orm::create($DB);
        try {
            $sentinel = $foreign->getReference(NetworkPortEntity::class, $port3);
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $before = $factories->getValue();
            $this->boolean($aggregate->getFromDB($agg_parent_port))->isTrue();
            $this->array(importArrayFromDB($aggregate->fields['networkports_id_list']))->isIdenticalTo([$port3]);
            $this->array($aggregate->showInstantiationForm($networkport, [], [$networkequipment]))->isIdenticalTo($form);
            $this->boolean($DB->delete('glpi_networkportaggregateorigins', [
                'networkportaggregates_id' => $aggregate->fields['id'],
            ]))->isTrue();
            $this->boolean($aggregate->getFromDB($agg_parent_port))->isTrue();
            $this->array(importArrayFromDB($aggregate->fields['networkports_id_list']))->isEmpty();
            $emptyForm = $aggregate->showInstantiationForm($networkport, [], [$networkequipment]);
            $this->array($emptyForm[$aggregate->getTypeName()]['inputs'][__('Origin port')]['values'])->isEmpty();
            $this->boolean($foreign->contains($sentinel))->isTrue();
        } finally {
            $foreign->clear();
        }
        $this->integer($factories->getValue() - $before)->isIdenticalTo(0);
    }

    public function testAggregateOriginValidationUsesCurrentScalarReads(): void
    {
        global $DB;
        $this->login();
        $equipment = getItemByTypeName('NetworkEquipment', '_test_networkequipment_1');
        $ports = [];
        foreach (['first', 'second', 'stale', 'parent'] as $name) {
            $ports[] = (int)(new LegacyNetworkPort())->add([
                'name' => 'Scalar aggregate ' . $name . ' ' . $this->getUniqueString(),
                'items_id' => $equipment->getID(), 'itemtype' => 'NetworkEquipment',
                'entities_id' => $equipment->fields['entities_id'],
            ]);
            $this->integer(end($ports))->isGreaterThan(0);
        }
        [$first, $second, $stale, $parent] = $ports;
        $aggregate = new NetworkPortAggregate();
        $added = (int)$aggregate->add([
            'networkports_id' => $parent, 'networkports_id_list' => [$first, (string)$second, $first],
        ]);
        $this->integer($added)->isGreaterThan(0);
        $aggregateId = (int)$aggregate->fields['id'];
        $this->array(importArrayFromDB($aggregate->fields['networkports_id_list']))->isIdenticalTo([$first, $second]);
        $em = Orm::create($DB);
        try {
            $loads = new class () {
                public int $ports = 0;
                public int $aggregates = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    $this->ports += (int)($event->getObject() instanceof NetworkPortEntity);
                    $this->aggregates += (int)($event->getObject() instanceof NetworkPortAggregateEntity);
                }
            };
            $em->getEventManager()->addEventListener([Events::postLoad], $loads);
            $repository = new NetworkPortAggregateRepository($em);
            $repository->replaceOrigins($aggregateId, [$second, $first, (string)$second]);
            $this->array($repository->originIds($aggregateId))->isIdenticalTo([$second, $first]);
            $this->integer($loads->ports)->isIdenticalTo(0);
            $this->integer($loads->aggregates)->isIdenticalTo(1);

            $this->exception(fn () => $aggregate->update([
                'id' => $added, 'networkports_id' => $parent,
                'networkports_id_list' => [$first, PHP_INT_MAX, PHP_INT_MAX - 1, $second],
            ]))->isInstanceOf(InvalidArgumentException::class)
                ->hasMessage('Unknown aggregate origin: ' . PHP_INT_MAX);
            $this->boolean($aggregate->getFromDB($parent))->isTrue();
            $this->array(importArrayFromDB($aggregate->fields['networkports_id_list']))->isIdenticalTo([$second, $first]);
            foreach ([null, '', 0] as $invalid) {
                $this->exception(fn () => $repository->replaceOrigins($aggregateId, [$first, $invalid]))
                    ->isInstanceOf(InvalidArgumentException::class)
                    ->hasMessage('Aggregate origins require positive port IDs');
                $this->array($repository->originIds($aggregateId))->isIdenticalTo([$second, $first]);
            }

            // A managed port is not evidence that it still exists after another
            // operation writes through the same underlying connection.
            $managed = $em->find(NetworkPortEntity::class, $stale);
            $this->object($managed)->isInstanceOf(NetworkPortEntity::class);
            $this->boolean($DB->delete('glpi_networkports', ['id' => $stale]))->isTrue();
            $this->exception(fn () => $repository->replaceOrigins($aggregateId, [$first, $stale]))
                ->isInstanceOf(InvalidArgumentException::class)
                ->hasMessage('Unknown aggregate origin: ' . $stale);
            $this->boolean($em->contains($managed))->isTrue();
            $this->array($repository->originIds($aggregateId))->isIdenticalTo([$second, $first]);

            $this->boolean($aggregate->update([
                'id' => $added, 'networkports_id' => $parent, 'networkports_id_list' => [],
            ]))->isTrue();
            $this->boolean($aggregate->getFromDB($parent))->isTrue();
            $this->array(importArrayFromDB($aggregate->fields['networkports_id_list']))->isEmpty();
        } finally {
            $em->clear();
        }
    }

    public function testConnectTwoPorts()
    {
        $this->login();

        $computer = getItemByTypeName('Computer', '_test_pc01');
        $networkport = new LegacyNetworkPort();

        $port_1_id = $networkport->add([
           'items_id'           => $computer->getID(),
           'itemtype'           => 'Computer',
           'entities_id'        => $computer->fields['entities_id'],
           'is_recursive'       => 0,
           'logical_number'     => 20,
           'instantiation_type' => 'NetworkPortEthernet',
           'name'               => 'wire-port-1',
        ]);
        $this->integer($port_1_id)->isGreaterThan(0);

        $port_2_id = $networkport->add([
           'items_id'           => $computer->getID(),
           'itemtype'           => 'Computer',
           'entities_id'        => $computer->fields['entities_id'],
           'is_recursive'       => 0,
           'logical_number'     => 21,
           'instantiation_type' => 'NetworkPortEthernet',
           'name'               => 'wire-port-2',
        ]);
        $this->integer($port_2_id)->isGreaterThan(0);

        $wire = new \NetworkPort_NetworkPort();
        $wire_id = $wire->add([
           'networkports_id_1' => $port_1_id,
           'networkports_id_2' => $port_2_id,
        ]);
        $this->integer($wire_id)->isGreaterThan(0);
        $this->boolean($wire->getFromDBForNetworkPort($port_1_id))->isTrue();
        $this->integer((int)$wire->getOppositeContact($port_1_id))->isEqualTo($port_2_id);
        $this->integer((int)$wire->getOppositeContact($port_2_id))->isEqualTo($port_1_id);
    }

    public function vlanProjectionFlagProvider(): array
    {
        return ['untagged' => [false], 'tagged' => [true]];
    }

    /** @dataProvider vlanProjectionFlagProvider */
    public function testVlanProjectionsUseMappedTypesAndTheUncommittedWriter(bool $tagged): void
    {
        global $DB;

        $this->login();
        $this->setEntity('_test_root_entity', false);
        $savedSession = $_SESSION;
        $writer = $DB;
        $connection = $writer->getDoctrineConnection();
        $depth = $connection->getTransactionNestingLevel();
        $this->integer($depth)->isGreaterThan(0);
        $this->boolean($connection->getNativeConnection()->inTransaction())->isTrue();
        $scope = $connection->captureManagedTransactionScope();
        $manager = null;
        try {
            $entity = (int)$_SESSION['glpiactive_entity'];
            $computer = $this->createItem('Computer', ['name' => $this->getUniqueString(), 'entities_id' => $entity]);
            $port = $this->createItem('NetworkPort', ['name' => $this->getUniqueString(), 'entities_id' => $entity,
                'is_recursive' => 0, 'itemtype' => 'Computer', 'items_id' => $computer->getID()]);
            $vlan = $this->createItem('Vlan', ['name' => $this->getUniqueString(), 'entities_id' => $entity, 'is_recursive' => 0]);
            $portId = (int)$port->getID();
            $vlanId = (int)$vlan->getID();
            $id = (new NetworkPort_Vlan())->assignVlan($portId, $vlanId, (int)$tagged);
            $this->integer($id)->isGreaterThan(0);
            $manager = Orm::create($writer);
            $this->variable($manager->getConnection())->isIdenticalTo($connection);
            $repository = new NetworkPortVlanRepository($manager);
            $expected = ['id' => $id, 'networkports_id' => $portId, 'vlans_id' => $vlanId, 'tagged' => $tagged];
            foreach ([false, true] as $current) {
                $this->array($repository->membership($id, current: $current))->isIdenticalTo($expected);
                $this->array($repository->selectedPair($portId, $vlanId, current: $current))->isIdenticalTo($expected);
            }
            $this->array($repository->membershipsForPort($portId))->isIdenticalTo([$expected]);
            $this->array((new VlanMembershipService($writer))->membershipsForPort($portId))->isIdenticalTo([$expected]);
            $this->array($repository->currentPort($portId))->isIdenticalTo([
                'id' => $portId, 'entity' => $entity, 'recursive' => false,
                'itemtype' => 'Computer', 'items_id' => (int)$computer->getID(),
            ]);
            $this->array($repository->currentVlan($vlanId))->isIdenticalTo([
                'id' => $vlanId, 'entity' => $entity, 'recursive' => false,
            ]);
            $this->boolean($repository->containsEntity(0, $entity))->isTrue();
            // These narrow reads type their projected values without loading
            // managed entities or relying on a previous identity-map observation.
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();

            $live = $manager->find(VlanEntity::class, $vlanId);
            $this->object($live)->isInstanceOf(VlanEntity::class);
            $service = new VlanMembershipService($writer);
            $this->array($service->membershipsForPort($portId))->isIdenticalTo([$expected]);
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $beforeReads = $factories->getValue();
            $portRows = $service->forPort($portId);
            $vlanRows = $service->forVlan($vlanId);
            $this->integer((int)$portRows[0]['assocID'])->isIdenticalTo($id);
            $this->integer((int)$portRows[0]['id'])->isIdenticalTo($vlanId);
            $this->integer((int)$vlanRows[0]['assocID'])->isIdenticalTo($id);
            $this->integer((int)$vlanRows[0]['id'])->isIdenticalTo($portId);
            $this->array($service->membershipsForPort($portId))->isIdenticalTo([$expected]);
            $this->integer($service->countForPort($portId))->isIdenticalTo(1);
            $this->integer($service->countForVlan($vlanId))->isIdenticalTo(1);
            $this->integer($connection->update('glpi_vlans', ['name' => 'fresh owned VLAN'], ['id' => $vlanId]))->isIdenticalTo(1);
            $this->integer($connection->update('glpi_networkports', ['name' => 'fresh owned port'], ['id' => $portId]))->isIdenticalTo(1);
            $this->integer($connection->update('glpi_networkports_vlans', ['tagged' => (int)!$tagged], ['id' => $id]))->isIdenticalTo(1);
            $this->string($service->forPort($portId)[0]['name'])->isIdenticalTo('fresh owned VLAN');
            $this->string($service->forVlan($vlanId)[0]['name'])->isIdenticalTo('fresh owned port');
            $expected['tagged'] = !$tagged;
            $this->array($service->membershipsForPort($portId))->isIdenticalTo([$expected]);
            $this->integer($service->countForPort(PHP_INT_MAX))->isIdenticalTo(0);
            $this->integer($service->countForVlan(PHP_INT_MAX))->isIdenticalTo(0);
            $this->string($portRows[0]['name'])->isIdenticalTo($vlan->fields['name']);
            $this->string($vlanRows[0]['name'])->isIdenticalTo($port->fields['name']);
            $this->boolean($manager->contains($live))->isTrue();
            $this->string($live->name)->isIdenticalTo($vlan->fields['name']);
            $this->integer($factories->getValue() - $beforeReads)->isIdenticalTo(0);
            $this->variable($DB)->isIdenticalTo($writer);
            $this->variable($DB->getDoctrineConnection())->isIdenticalTo($connection);
            $scope->assertActive();
            $this->boolean($connection->getNativeConnection()->inTransaction())->isTrue();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
        } finally {
            $manager?->clear();
            $_SESSION = $savedSession;
        }
    }

    public function testVlanAssignAndUnassign()
    {
        $this->login();
        $this->setEntity('_test_root_entity', false);

        $computer = getItemByTypeName('Computer', '_test_pc01');
        $networkport = new LegacyNetworkPort();
        $port_id = $networkport->add([
           'items_id'           => $computer->getID(),
           'itemtype'           => 'Computer',
           'entities_id'        => $computer->fields['entities_id'],
           'is_recursive'       => 0,
           'logical_number'     => 12,
           'mac'                => '00:24:81:eb:c7:12',
           'instantiation_type' => 'NetworkPortEthernet',
           'name'               => 'vlan-port',
        ]);
        $this->integer($port_id)->isGreaterThan(0);

        $vlan = new \Vlan();
        $vlan_id = $vlan->add([
           'name' => 'Functional VLAN',
           'tag'  => 120,
           'entities_id' => $computer->fields['entities_id'],
        ]);
        $this->integer($vlan_id)->isGreaterThan(0);
        $this->integer((int)$networkport->fields['entities_id'])->isIdenticalTo((int)$computer->fields['entities_id']);
        $this->integer((int)$vlan->fields['entities_id'])->isIdenticalTo((int)$computer->fields['entities_id']);
        $this->boolean($networkport->can($port_id, UPDATE))->isTrue();
        $this->boolean($vlan->can($vlan_id, UPDATE))->isTrue();

        $networkport_vlan = new \NetworkPort_Vlan();
        $relation_id = $networkport_vlan->assignVlan($port_id, $vlan_id, 1);
        $this->integer($relation_id)->isGreaterThan(0);
        $this->boolean($networkport_vlan->getFromDB($relation_id))->isTrue();
        $this->integer((int)$networkport_vlan->fields['tagged'])->isEqualTo(1);

        $this->boolean($networkport_vlan->unassignVlan($port_id, $vlan_id))->isTrue();
        $this->integer(countElementsInTable(
            \NetworkPort_Vlan::getTable(),
            [
                'networkports_id' => $port_id,
                'vlans_id'        => $vlan_id,
            ]
        ))->isEqualTo(0);
    }
}
