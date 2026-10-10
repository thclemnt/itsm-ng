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
use IPAddress as IPAddressModel;
use itsmng\Database\Entity\IPAddress as IPAddressEntity;
use itsmng\Database\Orm;

/* Test for inc/networkport.class.php */

class IPAddress extends DbTestCase
{
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

        // Retain the historical IPv6 word0 omission; changing that match is a separate fix.
        $connection = $GLOBALS['DB']->getDoctrineConnection();
        $ipv6Id = (int)$id;
        try {
            $this->integer($connection->update('glpi_ipaddresses', ['binary_0' => $expected['binary_0'] + 1], ['id' => $ipv6Id]))->isIdenticalTo(1);
            $identifiers = array_map(static fn (array $chain): int => (int)$chain[array_key_last($chain)]->getID(), IPAddressModel::getItemsByIPAddress($name));
            $this->array($identifiers)->contains($ipv6Id);
        } finally {
            $connection->update('glpi_ipaddresses', ['binary_0' => $expected['binary_0']], ['id' => $ipv6Id]);
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
}
