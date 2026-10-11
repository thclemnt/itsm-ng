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
use NetworkPort as NetworkPortModel;
use NetworkPortEthernet as NetworkPortEthernetModel;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use Doctrine\DBAL\Exception as DatabaseException;
use IPNetwork as IPNetworkModel;
use ReflectionProperty;
use NetworkName as NetworkNameModel;
use Plugin;
use itsmng\Database\CloneInput;
use IPAddress as IPAddressModel;
use itsmng\Database\Entity\IPAddress as IPAddressEntity;
use itsmng\Database\Orm;

/* Test for inc/networkport.class.php */

class IPAddress extends DbTestCase
{
    public function testNetworkNameFormsUseCurrentAddressValuesWithoutClearingLiveOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        $writer = null;
        try {
            $this->login();
            $entity = (int)$_SESSION['glpiactive_entity'];
            $computer = $this->createItem(ComputerModel::class, ['name' => 'form-address-' . $this->getUniqueString(), 'entities_id' => $entity]);
            $port = $this->createItem(NetworkPortModel::class, ['itemtype' => ComputerModel::class, 'items_id' => $computer->getID(), 'instantiation_type' => NetworkPortEthernetModel::class, 'name' => 'form-address-port']);
            $name = $this->createItem(NetworkNameModel::class, ['itemtype' => NetworkPortModel::class, 'items_id' => $port->getID(), 'name' => 'form-address-name']);
            $other = $this->createItem(NetworkNameModel::class, ['name' => 'other-form-address', 'entities_id' => $entity]);
            $first = $this->createItem(IPAddressModel::class, ['itemtype' => NetworkNameModel::class, 'items_id' => $name->getID(), 'name' => '192.0.2.201']);
            $second = $this->createItem(IPAddressModel::class, ['itemtype' => NetworkNameModel::class, 'items_id' => $name->getID(), 'name' => '192.0.2.202']);
            $this->createItem(IPAddressModel::class, ['itemtype' => NetworkNameModel::class, 'items_id' => $other->getID(), 'name' => '192.0.2.204']);
            $readForm = static function () use ($port): array {
                $form = NetworkNameModel::showFormForNetworkPort($port->getID());
                $section = reset($form);
                $fields = array_values(array_filter($section['inputs'], static fn ($field): bool => is_array($field) && ($field['type'] ?? null) === 'multiSelect'));
                return $fields[0]['values'];
            };
            $values = $readForm();
            $this->array($values)->hasSize(2);
            $byId = array_column($values, null, 'id');
            $this->array(array_keys($byId[$first->getID()]))->isIdenticalTo(['id', 'NetworkName__ipaddresses']);
            $this->string($byId[$first->getID()]['NetworkName__ipaddresses'])->isIdenticalTo('192.0.2.201');
            $this->output(static fn () => $name->showForm($name->getID()))->contains('192.0.2.201')->notContains('192.0.2.204');
            $connection = $DB->getDoctrineConnection();
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                foreach ($values as $value) {
                    $this->integer($value['id']);
                }
            }
            foreach ([null, 'null', 'NULL', 'Null', 'nUlL'] as $sentinel) {
                $this->array(IPAddressModel::getFormOptions(NetworkNameModel::class, $sentinel))->isEmpty();
            }
            $writer = Orm::create($DB);
            $live = $writer->find(IPAddressEntity::class, (int)$first->getID());
            $live->name = 'Independent unflushed address';
            $this->boolean($first->update(['id' => $first->getID(), 'name' => '192.0.2.203']))->isTrue();
            $connection->update('glpi_ipaddresses', ['name' => null, 'is_deleted' => true, 'is_dynamic' => true], ['id' => $second->getID()]);
            $fresh = array_column($readForm(), null, 'id');
            $this->string($fresh[$first->getID()]['NetworkName__ipaddresses'])->isIdenticalTo('192.0.2.203');
            $this->variable($fresh[$second->getID()]['NetworkName__ipaddresses'])->isNull();
            $this->string($byId[$first->getID()]['NetworkName__ipaddresses'])->isIdenticalTo('192.0.2.201');
            $this->boolean($writer->contains($live))->isTrue();
            $this->string($live->name)->isIdenticalTo('Independent unflushed address');
            Orm::read(
                $DB,
                function (EntityManager $outer) use ($first, $readForm): void {
                    $owned = $outer->find(IPAddressEntity::class, (int)$first->getID());
                    $owned->name = 'Outer unflushed address';
                    $fresh = array_column($readForm(), null, 'id');
                    $this->string($fresh[$first->getID()]['NetworkName__ipaddresses'])->isIdenticalTo('192.0.2.203');
                    $this->boolean($outer->contains($owned))->isTrue();
                    $this->string($owned->name)->isIdenticalTo('Outer unflushed address');
                }
            );
            $this->output(static fn () => $name->showForm($name->getID()))->contains('192.0.2.203')->notContains('192.0.2.201');
            $this->boolean($first->delete(['id' => $first->getID()], true))->isTrue();
            $this->array($readForm())->hasSize(1);
        } finally {
            $writer?->clear();
            $_SESSION = $session;
        }
    }

    public function testAddIPV4()
    {
        $this->login();

        //first create NetworkName
        $networkName = new \NetworkName();
        $networkName_id = $networkName->add(["name" => "test"]);
        $this->integer($networkName_id)->isGreaterThan(0);

        $IPV4ShouldWork = [];
        $IPV4ShouldWork["1.0.1.0"] = ["items_id" => $networkName_id,
                                      "itemtype" => "NetworkName",
                                      "version"  => 4,
                                      "name" => "1.0.1.0",
                                      "binary_0" => 0,
                                      "binary_1" => 0,
                                      "binary_2" => 65535 ,
                                      "binary_3" => 16777472];
        $IPV4ShouldWork["8.8.8.8"] = ["items_id" => $networkName_id,
                                      "itemtype" => "NetworkName",
                                      "version"  => 4,
                                      "name" => "8.8.8.8",
                                      "binary_0" => 0,
                                      "binary_1" => 0,
                                      "binary_2" => 65535 ,
                                      "binary_3" => 134744072];
        $IPV4ShouldWork["100.1.2.3"] = ["items_id" => $networkName_id,
                                        "itemtype" => "NetworkName",
                                         "version"  => 4,
                                         "name" => "100.1.2.3",
                                         "binary_0" => 0,
                                         "binary_1" => 0,
                                         "binary_2" => 65535 ,
                                         "binary_3" => 1677787651];
        $IPV4ShouldWork["100.1.2.3"] = ["items_id" => $networkName_id,
                                         "itemtype" => "NetworkName",
                                         "version"  => 4,
                                         "name" => "100.1.2.3",
                                         "binary_0" => 0,
                                         "binary_1" => 0,
                                         "binary_2" => 65535 ,
                                         "binary_3" => 1677787651];
        $IPV4ShouldWork["172.15.1.2"] = ["items_id" => $networkName_id,
                                         "itemtype" => "NetworkName",
                                         "version"  => 4,
                                         "name" => "172.15.1.2",
                                         "binary_0" => 0,
                                         "binary_1" => 0,
                                         "binary_2" => 65535 ,
                                         "binary_3" => 2886664450];
        $IPV4ShouldWork["172.32.1.2"] = ["items_id" => $networkName_id,
                                         "itemtype" => "NetworkName",
                                         "version"  => 4,
                                         "name" => "172.32.1.2",
                                         "binary_0" => 0,
                                         "binary_1" => 0,
                                         "binary_2" => 65535 ,
                                         "binary_3" => 2887778562];
        $IPV4ShouldWork["192.167.1.8"] = ["items_id" => $networkName_id,
                                         "itemtype" => "NetworkName",
                                         "version"  => 4,
                                         "name" => "192.167.1.8",
                                         "binary_0" => 0,
                                         "binary_1" => 0,
                                         "binary_2" => 65535 ,
                                         "binary_3" => 3232170248];
        $IPV4ShouldWork["::ffff:192.168.0.1"] = ["items_id" => $networkName_id,
                                                  "itemtype" => "NetworkName",
                                                  "version"  => 4,
                                                  "name" => "::ffff:192.168.0.1",
                                                  "binary_0" => 0,
                                                  "binary_1" => 0,
                                                  "binary_2" => 65535 ,
                                                  "binary_3" => 3232235521];

        //try to create each IPV4
        foreach ($IPV4ShouldWork as $name => $expected) {
            $ipAdress = new \IPAddress();
            $input = [
               "name" => $name,
               "itemtype" => "NetworkName",
               "items_id" => "$networkName_id"];
            $id = $ipAdress->add($input);
            $this->integer($id)->isGreaterThan(0);

            //check name store in DB
            $all_IP = getAllDataFromTable('glpi_ipaddresses', ['ORDER' => 'id']);
            $currentIP = end($all_IP);
            unset($currentIP['id']);
            unset($currentIP['entities_id']);
            unset($currentIP['date_mod']);
            unset($currentIP['date_creation']);
            unset($currentIP['is_deleted']);
            unset($currentIP['is_dynamic']);
            unset($currentIP['mainitems_id']);
            unset($currentIP['mainitemtype']);
            $expected += ['networknames_id' => $networkName_id, 'opaque_parent_id' => null];
            ksort($currentIP);
            ksort($expected);
            $this->array($currentIP)->isIdenticalTo($expected);
            $matches = array_values(array_filter(
                IPAddressModel::getItemsByIPAddress($name),
                static fn (array $chain): bool => (int)$chain[array_key_last($chain)]->getID() === (int)$id
            ));
            $this->array($matches)->hasSize(1);
            $this->array($matches[0])->hasSize(2);
            $this->string($matches[0][0]->getType())->isIdenticalTo('NetworkName');
            $this->integer((int)$matches[0][0]->getID())->isIdenticalTo((int)$networkName_id);
            $this->string($matches[0][1]->getType())->isIdenticalTo('IPAddress');
            $this->string($matches[0][1]->getTextual())->isIdenticalTo($expected['name']);
        }

        // Exercise the production rule path with an actual asset/port/name/address chain.
        $entityId = (int)$_SESSION['glpiactive_entity'];
        $computer = $this->createItem('Computer', [
            'name' => 'parsed-ip-' . $this->getUniqueString(), 'entities_id' => $entityId,
        ]);
        $port = $this->createItem('NetworkPort', [
            'itemtype' => 'Computer', 'items_id' => $computer->getID(), 'entities_id' => $entityId,
            'instantiation_type' => 'NetworkPortEthernet', 'name' => 'parsed-ip-port', 'logical_number' => 1,
        ]);
        $ownedName = $this->createItem('NetworkName', [
            'itemtype' => 'NetworkPort', 'items_id' => $port->getID(), 'entities_id' => $entityId,
            'name' => 'parsed-ip-owner',
        ]);
        $lookup = sprintf('198.18.%d.%d', $computer->getID() % 255, $port->getID() % 254 + 1);
        $ownedAddress = $this->createItem(IPAddressModel::class, [
            'name' => $lookup, 'itemtype' => 'NetworkName', 'items_id' => $ownedName->getID(),
        ]);
        $addressId = (int)$ownedAddress->getID();
        $chains = IPAddressModel::getItemsByIPAddress('  ' . $lookup . '  ');
        $ownedChains = array_values(array_filter($chains, static fn (array $chain): bool =>
            (int)$chain[array_key_last($chain)]->getID() === $addressId));
        $this->array($ownedChains)->hasSize(1);
        $this->array(array_map(static fn ($item): string => $item->getType(), $ownedChains[0]))
            ->isIdenticalTo(['Computer', 'NetworkPort', 'NetworkName', 'IPAddress']);
        $this->array(IPAddressModel::getUniqueItemByIPAddress($lookup, $entityId))
            ->isEqualTo(['id' => $computer->getID(), 'itemtype' => 'Computer']);
        $this->array(IPAddressModel::getUniqueItemByIPAddress($lookup, PHP_INT_MAX))->isEmpty();

        $connection = $GLOBALS['DB']->getDoctrineConnection();
        $oldWord = (int)$ownedAddress->getField('binary_3');
        $external = Orm::create($GLOBALS['DB']);
        try {
            $retained = $external->find(IPAddressEntity::class, $addressId);
            $this->object($retained)->isInstanceOf(IPAddressEntity::class);
            $nextWord = $oldWord + 1;
            $parsedNext = new IPAddressModel();
            $this->boolean($parsedNext->setAddressFromBinary([0, 0, 65535, $nextWord]))->isTrue();
            $nextLookup = $parsedNext->getTextual();
            $this->integer($connection->update('glpi_ipaddresses', ['binary_3' => $nextWord], ['id' => $addressId]))->isIdenticalTo(1);
            $oldIds = array_map(static fn (array $chain): int => (int)$chain[array_key_last($chain)]->getID(), IPAddressModel::getItemsByIPAddress($lookup));
            $this->array($oldIds)->notContains($addressId);
            $newIds = array_map(static fn (array $chain): int => (int)$chain[array_key_last($chain)]->getID(), IPAddressModel::getItemsByIPAddress($nextLookup));
            $this->array($newIds)->contains($addressId);
            $this->string($ownedChains[0][3]->getTextual())->isIdenticalTo($lookup);
            $this->boolean($external->contains($retained))->isTrue();
            $this->integer($retained->binary_3)->isIdenticalTo($oldWord);
            $this->integer($connection->update('glpi_ipaddresses', ['binary_3' => $oldWord, 'is_deleted' => 1], ['id' => $addressId]))->isIdenticalTo(1);
            $deletedIds = array_map(static fn (array $chain): int => (int)$chain[array_key_last($chain)]->getID(), IPAddressModel::getItemsByIPAddress($lookup));
            $this->array($deletedIds)->contains($addressId);
            $this->integer($connection->update('glpi_computers', ['is_deleted' => 1], ['id' => $computer->getID()]))->isIdenticalTo(1);
            $this->array(IPAddressModel::getUniqueItemByIPAddress($lookup, $entityId))->isEmpty();
            $this->integer($connection->update('glpi_computers', ['is_deleted' => 0, 'is_template' => 1], ['id' => $computer->getID()]))->isIdenticalTo(1);
            $this->array(IPAddressModel::getUniqueItemByIPAddress($lookup, $entityId))->isEmpty();
        } finally {
            $connection->update('glpi_ipaddresses', ['binary_3' => $oldWord, 'is_deleted' => 0], ['id' => $addressId]);
            $connection->update('glpi_computers', ['is_deleted' => 0, 'is_template' => 0], ['id' => $computer->getID()]);
            $external->clear();
        }
        foreach (['', 'not an address', null, ['198.18.0.1']] as $invalid) {
            $this->array(IPAddressModel::getItemsByIPAddress($invalid))->isEmpty();
        }

        $IPV4ShouldNotWork = [
           ".2.3.4",
           "1.2.3.",
           "1.2.3.256",
           "1.2.256.4",
           "1.256.3.4",
           "256.2.3.4",
           "1.2.3.4.5",
           "1..3.4",
        ];

        unset($_SESSION['glpicronuserrunning']);
        foreach ($IPV4ShouldNotWork as $name) {
            $ipAdress = new \IPAddress();
            $id = $ipAdress->add([
               "name" => $name,
               "itemtype" => "NetworkName",
               "items_id" => "$networkName_id"]);

            $expectedSession = [];
            $expectedSession[ERROR] = [
               "Invalid IP address: ".$name,
            ];

            $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'])->isIdenticalTo($expectedSession);
            $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

        }
    }

    public function testAddIPV6()
    {
        $this->login();

        //first create NetworkName
        $networkName = new \NetworkName();
        $networkName_id = $networkName->add(["name" => "test"]);
        $this->integer($networkName_id)->isGreaterThan(0);

        $IPV6ShouldWork = [];
        $IPV6ShouldWork["59FB::1005:CC57:6571"] = ["items_id" => $networkName_id,
                                                  "itemtype" => "NetworkName",
                                                  "version"  => 6,
                                                  "name"     => "59fb::1005:cc57:6571",   //tolower
                                                  "binary_0" => 1509621760,
                                                  "binary_1" => 0,
                                                  "binary_2" => 4101,
                                                  "binary_3" => 3428279665];
        $IPV6ShouldWork["21e5:69aa:ffff:1:e100:b691:1285:f56e"] = ["items_id" => $networkName_id,
                                                                    "itemtype" => "NetworkName",
                                                                    "version"  => 6,
                                                                    "name"     => "21e5:69aa:ffff:1:e100:b691:1285:f56e",
                                                                    "binary_0" => 568682922,
                                                                    "binary_1" => 4294901761,
                                                                    "binary_2" => 3774920337,
                                                                    "binary_3" => 310769006];
        $IPV6ShouldWork["::1"] = ["items_id" => $networkName_id,
                                   "itemtype" => "NetworkName",
                                   "version"  => 6,
                                   "name"     => "::1",           //loopback
                                   "binary_0" => 0,
                                   "binary_1" => 0,
                                   "binary_2" => 0,
                                   "binary_3" => 1];
        $IPV6ShouldWork["2001:db8:0:85a3:0:0:ac1f:8001"] = ["items_id" => $networkName_id,
                                                           "itemtype" => "NetworkName",
                                                           "version"  => 6,
                                                           "name"     => "2001:db8:0:85a3::ac1f:8001",
                                                           "binary_0" => 536939960,
                                                           "binary_1" => 34211,
                                                           "binary_2" => 0,
                                                           "binary_3" => 2887745537];
        $IPV6ShouldWork["2001:db8:1f89:ffff:ffff:ffff:ffff:ffff"] = ["items_id" => $networkName_id,
                                                                       "itemtype" => "NetworkName",
                                                                       "version"  => 6,
                                                                       "name"     => "2001:db8:1f89:ffff:ffff:ffff:ffff:ffff",
                                                                       "binary_0" => 536939960,
                                                                       "binary_1" => 529137663,
                                                                       "binary_2" => 4294967295,
                                                                       "binary_3" => 4294967295];

        //try to create each IPV6
        foreach ($IPV6ShouldWork as $name => $expected) {
            $ipAdress = new \IPAddress();
            $input = [
               "name" => $name,
               "itemtype" => "NetworkName",
               "items_id" => "$networkName_id"];
            $id = $ipAdress->add($input);
            $this->integer($id)->isGreaterThan(0);

            //check name store in DB
            $all_IP = getAllDataFromTable('glpi_ipaddresses', ['ORDER' => 'id']);
            $currentIP = end($all_IP);
            unset($currentIP['id']);
            unset($currentIP['entities_id']);
            unset($currentIP['date_mod']);
            unset($currentIP['date_creation']);
            unset($currentIP['is_deleted']);
            unset($currentIP['is_dynamic']);
            unset($currentIP['mainitems_id']);
            unset($currentIP['mainitemtype']);
            //var_dump($currentIP);
            $expected += ['networknames_id' => $networkName_id, 'opaque_parent_id' => null];
            ksort($currentIP);
            ksort($expected);
            $this->array($currentIP)->isIdenticalTo($expected);
            $matches = array_values(array_filter(
                IPAddressModel::getItemsByIPAddress($name),
                static fn (array $chain): bool => (int)$chain[array_key_last($chain)]->getID() === (int)$id
            ));
            $this->array($matches)->hasSize(1);
            $this->array($matches[0])->hasSize(2);
            $this->string($matches[0][0]->getType())->isIdenticalTo('NetworkName');
            $this->integer((int)$matches[0][0]->getID())->isIdenticalTo((int)$networkName_id);
            $this->string($matches[0][1]->getType())->isIdenticalTo('IPAddress');
            $this->string($matches[0][1]->getTextual())->isIdenticalTo($expected['name']);
        }

        // A changed IPv6 prefix must stop matching the old normalized address.
        $connection = $GLOBALS['DB']->getDoctrineConnection();
        $ipv6Id = (int)$id;
        try {
            $this->integer($connection->update('glpi_ipaddresses', ['binary_0' => $expected['binary_0'] + 1], ['id' => $ipv6Id]))->isIdenticalTo(1);
            $identifiers = array_map(static fn (array $chain): int => (int)$chain[array_key_last($chain)]->getID(), IPAddressModel::getItemsByIPAddress($name));
            $this->array($identifiers)->notContains($ipv6Id);
            $changed = new IPAddressModel();
            $this->boolean($changed->setAddressFromBinary([
                $expected['binary_0'] + 1, $expected['binary_1'], $expected['binary_2'], $expected['binary_3'],
            ]))->isTrue();
            $changedIdentifiers = array_map(static fn (array $chain): int => (int)$chain[array_key_last($chain)]->getID(), IPAddressModel::getItemsByIPAddress($changed->getTextual()));
            $this->array($changedIdentifiers)->contains($ipv6Id);
        } finally {
            $connection->update('glpi_ipaddresses', ['binary_0' => $expected['binary_0']], ['id' => $ipv6Id]);
        }

        // Two real assets in the same entity differ only in the first IPv6 word.
        // Entity filtering cannot mask an incorrect prefix match in the rule path.
        $entityId = (int)$_SESSION['glpiactive_entity'];
        $owners = [];
        foreach (['2001', '2002'] as $prefix) {
            $computer = $this->createItem('Computer', [
                'name' => 'exact-ipv6-' . $this->getUniqueString(), 'entities_id' => $entityId,
            ]);
            $port = $this->createItem('NetworkPort', [
                'itemtype' => 'Computer', 'items_id' => $computer->getID(), 'entities_id' => $entityId,
                'instantiation_type' => 'NetworkPortEthernet', 'name' => 'exact-ipv6-port', 'logical_number' => 1,
            ]);
            $ownedName = $this->createItem('NetworkName', [
                'itemtype' => 'NetworkPort', 'items_id' => $port->getID(), 'entities_id' => $entityId,
                'name' => 'exact-ipv6-owner',
            ]);
            if (!$owners) {
                $suffix = [$computer->getID() % 65535 + 1, $port->getID() % 65535 + 1];
            }
            $compressed = sprintf('%s:db8:5a6b::%x:%x', $prefix, $suffix[0], $suffix[1]);
            $address = $this->createItem(IPAddressModel::class, [
                'name' => $compressed, 'itemtype' => 'NetworkName', 'items_id' => $ownedName->getID(),
            ]);
            $owners[] = [$computer, $port, $ownedName, $address, $compressed,
                sprintf('%s:0DB8:5A6B:0000:0000:0000:%04X:%04X', $prefix, $suffix[0], $suffix[1])];
        }
        $this->integer((int)$owners[0][3]->getField('binary_0'))
            ->isNotEqualTo((int)$owners[1][3]->getField('binary_0'));
        foreach ([1, 2, 3] as $word) {
            $this->integer((int)$owners[0][3]->getField('binary_' . $word))
                ->isIdenticalTo((int)$owners[1][3]->getField('binary_' . $word));
        }
        foreach ($owners as [$computer, $port, $ownedName, $address, $compressed, $expanded]) {
            foreach ([$compressed, $expanded, '  ' . $expanded . '  '] as $lookup) {
                $chains = IPAddressModel::getItemsByIPAddress($lookup);
                $this->array($chains)->hasSize(1);
                $this->array(array_map(static fn ($item): string => $item->getType(), $chains[0]))
                    ->isIdenticalTo(['Computer', 'NetworkPort', 'NetworkName', 'IPAddress']);
                $this->array(array_map(static fn ($item): int => (int)$item->getID(), $chains[0]))
                    ->isIdenticalTo([(int)$computer->getID(), (int)$port->getID(), (int)$ownedName->getID(), (int)$address->getID()]);
                $this->string($chains[0][3]->getTextual())->isIdenticalTo($compressed);
                $this->array(IPAddressModel::getUniqueItemByIPAddress($lookup, $entityId))
                    ->isEqualTo(['id' => $computer->getID(), 'itemtype' => 'Computer']);
                $this->array(IPAddressModel::getUniqueItemByIPAddress($lookup, PHP_INT_MAX))->isEmpty();
            }
        }

        $IPV6ShouldNotWork = [
           "56FE::2159:5BBC::6594",
           "2002:0001:3238:DFE1:0063:0000:0000:FEFB:0045", // more than 8 groups
           "1200:0000:AB00:1234:O000:2552:7777:1313",    // invalid characters present
           "02001:0000:1234:0000:0000:C1C0:ABCD:0876", //extra 0 not allowed
           "2001:0000:1234:0000:0000:C1C0:ABCD:0876  0",  //junk after valid address
           "3ffe:b00::1::a", // double "::"
           "::1111:2222:3333:4444:5555:6666::", //double "::"
           "", //empty "::"
           "1:2:3::4:5::7:8",  // Double "::""
           "12345::6:7:8" //more than 4 digit
        ];

        unset($_SESSION['glpicronuserrunning']);
        foreach ($IPV6ShouldNotWork as $name) {
            $ipAdress = new \IPAddress();
            $id = $ipAdress->add([
               "name" => $name,
               "itemtype" => "NetworkName",
               "items_id" => "$networkName_id"]);

            $expectedSession = [];
            $expectedSession[ERROR] = [
               "Invalid IP address: ".$name,
            ];

            $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'])->isIdenticalTo($expectedSession);
            $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

        }
    }
    public function testAdoptedNameOwnershipPreservesAddressContextCloneAndPurge(): void
    {
        $this->login();
        global $DB;
        $entity = (int)$_SESSION['glpiactive_entity'];
        $computer = $this->createItem('Computer', ['name' => 'ip-parent-' . $this->getUniqueString(), 'entities_id' => $entity]);
        $port = $this->createItem('NetworkPort', ['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'entities_id' => $entity, 'instantiation_type' => 'NetworkPortEthernet', 'name' => 'ip-port-' . $this->getUniqueString()]);
        $first = $this->createItem(NetworkNameModel::class, ['itemtype' => 'NetworkPort', 'items_id' => $port->getID(), 'name' => 'ip-first-' . strtolower($this->getUniqueString())]);
        $second = $this->createItem(NetworkNameModel::class, ['entities_id' => $entity, 'name' => 'ip-second-' . strtolower($this->getUniqueString())]);
        $network = $this->createItem(IPNetworkModel::class, ['name' => 'ip-link-' . $this->getUniqueString(), 'entities_id' => $entity, 'network' => '192.0.2.0 / 255.255.255.0', 'gateway' => '192.0.2.1']);
        $address = $this->createItem(IPAddressModel::class, ['itemtype' => 'NetworkName', 'items_id' => $first->getID(), 'name' => '192.0.2.61']);
        $id = (int)$address->getID();
        $this->integer((int)$DB->getDoctrineConnection()->fetchOne('SELECT COUNT(*) FROM glpi_ipaddresses_ipnetworks WHERE ipaddresses_id = ? AND ipnetworks_id = ?', [$id, $network->getID()]))->isIdenticalTo(1);
        $this->integer((int)$address->fields['networknames_id'])->isIdenticalTo((int)$first->getID());
        $this->variable($address->fields['opaque_parent_id'])->isNull();
        $this->string($address->fields['mainitemtype'])->isIdenticalTo('Computer');
        $this->integer((int)$address->fields['mainitems_id'])->isIdenticalTo((int)$computer->getID());
        $cloneId = $address->clone(['items_id' => $second->getID()]);
        $this->integer($cloneId)->isGreaterThan(0);
        $clone = new IPAddressModel();
        $this->boolean($clone->getFromDB($cloneId))->isTrue();
        $this->integer((int)$clone->fields['networknames_id'])->isIdenticalTo((int)$second->getID());
        $this->variable($clone->fields['opaque_parent_id'])->isNull();
        $this->integer((int)$clone->fields['mainitems_id'])->isIdenticalTo(0);
        $custom = $this->createItem(IPAddressModel::class, ['itemtype' => IPAddressCustomNameParent::class, 'items_id' => $second->getID(), 'name' => '192.0.2.62']);
        $customId = (int)$custom->getID();
        $this->variable($custom->fields['networknames_id'])->isNull();
        $this->integer((int)$custom->fields['opaque_parent_id'])->isIdenticalTo((int)$second->getID());
        $this->boolean($custom->update(['id' => $customId, 'name' => '192.0.2.64']))->isTrue();
        $this->string($custom->fields['itemtype'])->isIdenticalTo(IPAddressCustomNameParent::class);
        $this->integer((int)$custom->fields['items_id'])->isIdenticalTo((int)$second->getID());
        foreach ([0, -1, null] as $invalid) {
            $refused = new IPAddressModel();
            $this->boolean($refused->add(['itemtype' => 'NetworkName', 'items_id' => $invalid, 'name' => '192.0.2.63']))->isFalse();
        }
        $connection = $DB->getDoctrineConnection();
        foreach ([
            ['networknames_id' => PHP_INT_MAX],
            ['networknames_id' => 0],
            ['itemtype' => 'IPAddressCustomNameParent'],
            ['opaque_parent_id' => 1],
        ] as $invalid) {
            $connection->beginTransaction();
            try {
                $this->exception(static fn () => $connection->update('glpi_ipaddresses', $invalid, ['id' => $id]))->isInstanceOf(DatabaseException::class);
            } finally {
                $connection->rollBack();
            }
        }
        $connection->beginTransaction();
        try {
            $this->exception(static fn () => $connection->delete('glpi_networknames', ['id' => $first->getID()]))->isInstanceOf(DatabaseException::class);
        } finally {
            $connection->rollBack();
        }
        $this->boolean($first->delete(['id' => $first->getID()], true))->isTrue();
        $this->boolean((new IPAddressModel())->getFromDB($id))->isFalse();
        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_ipaddresses_ipnetworks WHERE ipaddresses_id = ?', [$id]))->isIdenticalTo(0);
        $this->boolean((new IPAddressModel())->getFromDB($cloneId))->isTrue();
        $this->boolean((new IPAddressModel())->getFromDB($customId))->isTrue();
    }

    public function testOpenAddressParentInputPreservesZeroOpaqueAndCloneIdentity(): void
    {
        $record = new IPAddressEntity();
        $owned = $record->normalizeInput(['itemtype' => 'NetworkName', 'items_id' => '12']);
        $this->array($owned)->isIdenticalTo(['itemtype' => 'NetworkName', 'networknames_id' => 12, 'opaque_parent_id' => null]);
        $zero = $record->normalizeInput(['itemtype' => 'NetworkName', 'items_id' => 0]);
        $this->array($zero)->isIdenticalTo(['itemtype' => 'NetworkName', 'networknames_id' => null, 'opaque_parent_id' => null]);
        $this->array($record->normalizeInput($zero + ['items_id' => 0]))->isIdenticalTo($zero);
        foreach (['', 'networkname', 'PluginCustomParent'] as $kind) {
            $this->array($record->normalizeInput(['itemtype' => $kind, 'items_id' => -9]))
                ->isIdenticalTo(['itemtype' => $kind, 'networknames_id' => null, 'opaque_parent_id' => -9]);
        }
        foreach ([
            ['itemtype' => 'NetworkName', 'items_id' => -1],
            ['itemtype' => 'NetworkName', 'items_id' => null],
            ['itemtype' => null, 'items_id' => 0],
            ['itemtype' => 'NetworkName', 'networknames_id' => 12, 'items_id' => 13],
            ['itemtype' => 'NetworkName', 'networknames_id' => 12, 'opaque_parent_id' => 12],
            ['itemtype' => 'PluginCustomParent', 'items_id' => 12, 'networknames_id' => 12],
        ] as $invalid) {
            $this->exception(static fn () => $record->normalizeInput($invalid))->isInstanceOf(InvalidArgumentException::class);
        }
        $source = ['itemtype' => 'NetworkName', 'items_id' => 12, 'networknames_id' => 12, 'opaque_parent_id' => null];
        $opaque = CloneInput::merge(IPAddressModel::getTable(), $source, ['itemtype' => 'PluginCustomParent', 'items_id' => -9]);
        $this->variable($opaque['networknames_id'])->isNull();
        $this->integer($opaque['opaque_parent_id'])->isIdenticalTo(-9);
        $this->integer($opaque['items_id'])->isIdenticalTo(-9);
    }

    public function testPreparedAddressOwnerWriteRechecksActualNamePermission(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $this->login();
        $entity = (int)$_SESSION['glpiactive_entity'];
        $first = $this->createItem(NetworkNameModel::class, ['name' => 'ip-allowed-' . strtolower($this->getUniqueString()), 'entities_id' => $entity]);
        $second = $this->createItem(NetworkNameModel::class, ['name' => 'ip-denied-' . strtolower($this->getUniqueString()), 'entities_id' => $entity]);
        $address = $this->createItem(IPAddressModel::class, ['itemtype' => 'NetworkName', 'items_id' => $first->getID(), 'name' => '192.0.2.65']);
        $id = (int)$address->getID();
        $probe = new IPAddressPreparedNameOwner();
        $this->boolean($probe->getFromDB($id))->isTrue();
        $probe->preparedName = (int)$second->getID();
        $connection = $DB->getDoctrineConnection();
        $before = $connection->fetchAssociative('SELECT * FROM glpi_ipaddresses WHERE id=?', [$id]);
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        $denials = 0;
        try {
            $plugins->setValue(null, [...$active, 'ip_address_parent_fixture']);
            $PLUGIN_HOOKS['item_can']['ip_address_parent_fixture'][NetworkNameModel::class] =
                static function (NetworkNameModel $parent) use ($second, &$denials): void {
                    if ((int)$parent->getID() === (int)$second->getID()) {
                        ++$denials;
                        $parent->right = false;
                    }
                };
            $this->boolean($probe->update(['id' => $id, 'name' => '192.0.2.66']))->isFalse();
            $this->hasSessionMessages(ERROR, [__('Cannot update item: not enough right on the parent(s) item(s)')]);
            $this->integer($denials)->isGreaterThan(0);
            $this->array($connection->fetchAssociative('SELECT * FROM glpi_ipaddresses WHERE id=?', [$id]))->isIdenticalTo($before);
            $this->integer((int)$probe->fields['items_id'])->isIdenticalTo((int)$first->getID());
        } finally {
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
        $this->boolean($probe->update(['id' => $id, 'name' => '192.0.2.66']))->isTrue();
        $this->integer((int)$connection->fetchOne('SELECT networknames_id FROM glpi_ipaddresses WHERE id=?', [$id]))->isIdenticalTo((int)$second->getID());
        $this->variable($connection->fetchOne('SELECT opaque_parent_id FROM glpi_ipaddresses WHERE id=?', [$id]))->isNull();
    }

}

class IPAddressCustomNameParent extends NetworkNameModel
{
    public static function getTable($classname = null)
    {
        return NetworkNameModel::getTable();
    }
}

class IPAddressPreparedNameOwner extends IPAddressModel
{
    public ?int $preparedName = null;

    public static function getTable($classname = null)
    {
        return IPAddressModel::getTable();
    }

    public function pre_updateInDB()
    {
        parent::pre_updateInDB();
        if ($this->preparedName !== null) {
            $this->fields['networknames_id'] = $this->preparedName;
            $this->updates[] = 'networknames_id';
        }
    }
}
