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

use Contact_Supplier as ContactSupplierModel;
use DbTestCase;
use Supplier as SupplierModel;
use itsmng\Database\Entity\Contact as ContactEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ContactRepository;

class Contact_Supplier extends DbTestCase
{
    public function testLinkAndReadSupplierDataFromContact()
    {
        $this->login();

        $supplier = new \Supplier();
        $supplier_id = $supplier->add([
           'name'        => 'supplier-' . $this->getUniqueString(),
           'entities_id' => 0,
           'website'     => 'https://example.com',
           'address'     => '1 Test street',
           'town'        => 'Test City',
           'postcode'    => '12345',
           'country'     => 'Testland',
        ]);
        $this->integer((int)$supplier_id)->isGreaterThan(0);

        $contact = new \Contact();
        $contact_id = $contact->add([
           'name'        => 'contact-' . $this->getUniqueString(),
           'firstname'   => 'first-' . $this->getUniqueString(),
           'entities_id' => 0,
        ]);
        $this->integer((int)$contact_id)->isGreaterThan(0);

        $relation = new \Contact_Supplier();
        $relation_id = $relation->add([
           'contacts_id'  => $contact_id,
           'suppliers_id' => $supplier_id,
        ]);
        $this->integer((int)$relation_id)->isGreaterThan(0);

        $this->boolean($contact->getFromDB($contact_id))->isTrue();
        $this->string($contact->getWebsite())->isEqualTo('https://example.com');
        $address = $contact->getAddress();
        $this->array($address)->hasKey('address')->hasKey('town')->hasKey('country');
        $this->string($address['address'])->isEqualTo('1 Test street');

        $connection = $GLOBALS['DB']->getDoctrineConnection();
        $this->integer(ContactSupplierModel::countForItem($contact))->isIdenticalTo(1);
        $this->integer(ContactSupplierModel::countForItem($supplier))->isIdenticalTo(1);
        $columns = array_keys($address);
        sort($columns);
        $this->array($columns)->isIdenticalTo(['address', 'country', 'name', 'postcode', 'state', 'town']);
        $this->string($address['name'])->isIdenticalTo($supplier->fields['name']);
        $this->output(static fn () => ContactSupplierModel::showForContact($contact))->contains('https://example.com');
        $this->output(static fn () => ContactSupplierModel::showForSupplier($supplier))->contains($contact->fields['name']);

        // Both company fields choose the first supplier by supplier ID, even with another link.
        $second = $this->createItem(SupplierModel::class, [
            'name' => 'second-supplier-' . $this->getUniqueString(), 'entities_id' => 0,
            'website' => 'https://second.example.com', 'address' => 'Second street',
        ]);
        $secondLink = $this->createItem(ContactSupplierModel::class, [
            'contacts_id' => $contact_id, 'suppliers_id' => $second->getID(),
        ]);
        $this->integer(ContactSupplierModel::countForItem($contact))->isIdenticalTo(2);
        $this->string($contact->getWebsite())->isIdenticalTo('https://example.com');
        $this->array($contact->getAddress())->isIdenticalTo($address);

        // Entity scope applies to the opposite endpoint; cron retains its unrestricted count.
        $otherEntity = $this->createItem('Entity', [
            'name' => 'contact-scope-' . $this->getUniqueString(), 'entities_id' => 0,
        ]);
        $originalSession = $_SESSION;
        $originalSelf = $_SERVER['PHP_SELF'] ?? null;
        try {
            unset($_SESSION['glpicronuserrunning']);
            $_SESSION['glpishowallentities'] = false;
            $_SESSION['glpiactiveentities'] = [0];
            $this->integer($connection->update('glpi_suppliers', [
                'entities_id' => (int)$otherEntity->getID(), 'is_recursive' => 0,
            ], ['id' => $second->getID()]))->isIdenticalTo(1);
            $this->integer(ContactSupplierModel::countForItem($contact))->isIdenticalTo(1);
            $this->integer(ContactSupplierModel::countForItem($second))->isIdenticalTo(1);
            $this->output(static fn () => ContactSupplierModel::showForContact($contact))->notContains('https://second.example.com');
            $_SESSION['glpicronuserrunning'] = true;
            $_SERVER['PHP_SELF'] = '/front/cron.php';
            $this->integer(ContactSupplierModel::countForItem($contact))->isIdenticalTo(2);
        } finally {
            $connection->update('glpi_suppliers', ['entities_id' => 0], ['id' => $second->getID()]);
            $_SESSION = $originalSession;
            if ($originalSelf === null) {
                unset($_SERVER['PHP_SELF']);
            } else {
                $_SERVER['PHP_SELF'] = $originalSelf;
            }
        }
        $this->boolean($secondLink->delete(['id' => $secondLink->getID()], true))->isTrue();
        $this->integer(ContactSupplierModel::countForItem($contact))->isIdenticalTo(1);

        // Completed scalar values observe direct writes and preserve their earlier snapshots.
        $this->integer($connection->update('glpi_suppliers', [
            'website' => null, 'address' => 'Fresh supplier street',
        ], ['id' => $supplier_id]))->isIdenticalTo(1);
        $this->variable($contact->getWebsite())->isNull();
        $freshAddress = $contact->getAddress();
        $this->string($freshAddress['address'])->isIdenticalTo('Fresh supplier street');
        $this->string($address['address'])->isIdenticalTo('1 Test street');
        $this->integer($connection->update('glpi_suppliers', ['website' => 'https://fresh.example.com'], ['id' => $supplier_id]))->isIdenticalTo(1);
        $this->string($contact->getWebsite())->isIdenticalTo('https://fresh.example.com');

        $manager = Orm::create($GLOBALS['DB']);
        try {
            $selected = $manager->find(ContactEntity::class, (int)$contact_id);
            $this->object($selected)->isInstanceOf(ContactEntity::class);
            $selected->phone = '0102030405';
            $this->integer(ContactSupplierModel::countForItem($contact))->isIdenticalTo(1);
            $this->integer(ContactSupplierModel::countForItem($supplier))->isIdenticalTo(1);
            $this->string($contact->getWebsite())->isIdenticalTo('https://fresh.example.com');
            $this->array($contact->getAddress())->isIdenticalTo($freshAddress);
            $this->output(static fn () => ContactSupplierModel::showForSupplier($supplier))->contains($contact->fields['name']);
            $this->output(static fn () => ContactSupplierModel::showForContact($contact))->contains('https://fresh.example.com');
            $this->boolean($manager->contains($selected))->isTrue();
            $this->string($selected->phone)->isIdenticalTo('0102030405');
            $rows = (new ContactRepository($manager))->related((int)$supplier_id, false, null);
            $this->array($rows)->hasSize(1);
            $this->integer((int)$rows[0]['id'])->isIdenticalTo((int)$contact_id);
            $this->string($rows[0]['phone'])->isIdenticalTo('0102030405');
            $this->boolean($manager->contains($selected))->isTrue();
            $manager->flush();
            $this->boolean($contact->getFromDB($contact_id))->isTrue();
            $this->string($contact->getField('phone'))->isIdenticalTo('0102030405');
        } finally {
            $manager->clear();
        }

        $this->boolean($relation->delete(['id' => $relation_id]))->isTrue();
        $this->integer((int)countElementsInTable(
            \Contact_Supplier::getTable(),
            [
                'contacts_id'  => $contact_id,
                'suppliers_id' => $supplier_id,
            ]
        ))->isEqualTo(0);
        $this->integer(ContactSupplierModel::countForItem($contact))->isIdenticalTo(0);
        $this->integer(ContactSupplierModel::countForItem($supplier))->isIdenticalTo(0);
        $this->variable($contact->getAddress())->isNull();
        $this->string($contact->getWebsite())->isIdenticalTo('');
    }
}
