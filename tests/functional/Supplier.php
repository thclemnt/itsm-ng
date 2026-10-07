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

use Contact;
use Contact_Supplier;
use DbTestCase;
use Domain;
use InvalidArgumentException;
use itsmng\Database\Entity\Domain as DomainRecord;
use itsmng\Database\Entity\Entity as EntityRecord;
use itsmng\Database\Entity\Supplier as SupplierRecord;
use itsmng\Database\Orm;
use RuntimeException;
use Supplier as SupplierModel;
use Transfer;

class Supplier extends DbTestCase
{
    public function testCrud()
    {
        $this->login();

        $obj = new SupplierModel();
        $id = $obj->add([
           'name'        => 'supplier-' . $this->getUniqueString(),
           'entities_id' => 0,
           'email'       => 'supplier-' . mt_rand(1000, 9999) . '@example.com',
           'is_active'   => 1,
        ]);
        $this->integer((int)$id)->isGreaterThan(0);
        $this->boolean($obj->getFromDB($id))->isTrue();

        $this->boolean($obj->update([
           'id'          => $id,
           'phonenumber' => '0102030405',
        ]))->isTrue();
        $this->boolean($obj->getFromDB($id))->isTrue();
        $this->string($obj->getField('phonenumber'))->isEqualTo('0102030405');

        $this->boolean($obj->delete(['id' => $id]))->isTrue();
    }

    public function testScopeChangesRetainCommercialDomainOwnership(): void
    {
        global $DB;

        [$supplier, $domain, $source, $child, $sibling] = $this->commercialDomainFixture();
        $connection = $DB->getDoctrineConnection();
        $scope = $DB->captureManagedTransactionScope();
        $level = $connection->getTransactionNestingLevel();
        $beforeSupplier = $connection->fetchAssociative('SELECT * FROM glpi_suppliers WHERE id = ?', [$supplier->getID()]);
        $beforeDomain = $connection->fetchAssociative('SELECT * FROM glpi_domains WHERE id = ?', [$domain->getID()]);
        foreach ([['is_recursive' => 0], ['entities_id' => $sibling], ['is_recursive' => 0, 'entities_id' => $sibling]] as $change) {
            $this->boolean($supplier->update(['id' => $supplier->getID()] + $change))
                ->isFalse('Supplier scope changes must preserve existing commercial Domain ownership');
            $this->hasSessionMessages(ERROR, ['Domain commercial supplier must belong to its owner entity or a recursive ancestor.']);
            $this->array($connection->fetchAssociative('SELECT * FROM glpi_suppliers WHERE id = ?', [$supplier->getID()]))
                ->isIdenticalTo($beforeSupplier);
            $this->array($connection->fetchAssociative('SELECT * FROM glpi_domains WHERE id = ?', [$domain->getID()]))
                ->isIdenticalTo($beforeDomain);
            $scope->assertActive();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        }
        // Decorative changes and an unchanged scope remain ordinary valid updates.
        $this->boolean($supplier->update(['id' => $supplier->getID(), 'phonenumber' => '0123456789', 'is_recursive' => 1]))->isTrue();
        // Moving the supplier to its sole Domain's owner permits local ownership.
        $this->boolean($supplier->update(['id' => $supplier->getID(), 'entities_id' => $child, 'is_recursive' => 0]))->isTrue();
        $this->boolean($domain->update(['id' => $domain->getID(), 'comment' => 'Still a valid assignment']))->isTrue();
        $scope->assertActive();
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
    }

    public function testTransferRefusesToStrandCommercialDomains(): void
    {
        global $DB;

        [$supplier, $domain, $source, $child, $sibling] = $this->commercialDomainFixture();
        $contact = $this->createItem(Contact::class, [
            'name' => 'Supplier transfer contact ' . $this->getUniqueString(),
            'entities_id' => $source,
        ]);
        $binding = $this->createItem(Contact_Supplier::class, [
            'contacts_id' => $contact->getID(), 'suppliers_id' => $supplier->getID(),
        ]);
        $connection = $DB->getDoctrineConnection();
        $scope = $DB->captureManagedTransactionScope();
        $level = $connection->getTransactionNestingLevel();
        $rows = [
            'glpi_suppliers' => $supplier->getID(), 'glpi_domains' => $domain->getID(),
            'glpi_contacts' => $contact->getID(), 'glpi_contacts_suppliers' => $binding->getID(),
        ];
        $snapshot = [];
        foreach ($rows as $table => $id) {
            $snapshot[$table] = $connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' WHERE id = ?', [$id]);
        }
        $error = null;
        try {
            (new Transfer())->moveItems(['Supplier' => [$supplier->getID()]], $sibling, []);
        } catch (RuntimeException $caught) {
            // The test logger throws after Transfer has rolled back its owned frame.
            $error = $caught;
        }
        $this->boolean($error instanceof RuntimeException)->isTrue('Supplier Transfer must reject an out-of-scope commercial Domain');
        $this->string($error->getMessage())->contains('Domain commercial supplier must belong to its owner entity or a recursive ancestor.');
        foreach ($rows as $table => $id) {
            $this->array($connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' WHERE id = ?', [$id]))
                ->isIdenticalTo($snapshot[$table]);
        }
        $scope->assertActive();
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        // A supplier without incoming Domains can still follow the normal transfer path.
        $unlinked = $this->createItem(SupplierModel::class, [
            'name' => 'Independent supplier ' . $this->getUniqueString(), 'entities_id' => $source,
        ]);
        $this->boolean((new Transfer())->moveItems(['Supplier' => [$unlinked->getID()]], $sibling, []))->isTrue();
        $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_suppliers WHERE id = ?', [$unlinked->getID()]))->isIdenticalTo($sibling);
        $scope->assertActive();
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
    }

    public function testOrmSupplierScopeSharesDomainOwnership(): void
    {
        global $DB;

        [$supplier, $domain, $source, $child, $sibling] = $this->commercialDomainFixture();
        $connection = $DB->getDoctrineConnection();
        $scope = $DB->captureManagedTransactionScope();
        $level = $connection->getTransactionNestingLevel();
        foreach (['is_recursive', 'entities'] as $field) {
            $manager = Orm::create($DB);
            try {
                $record = $manager->find(SupplierRecord::class, $supplier->getID());
                $record->$field = $field === 'is_recursive' ? false
                    : $manager->getReference(EntityRecord::class, $sibling);
                $error = null;
                try {
                    $manager->flush();
                } catch (InvalidArgumentException $caught) {
                    $error = $caught;
                }
                $this->boolean($error instanceof InvalidArgumentException)->isTrue('The owning ORM mutation must enforce commercial Domain ownership');
                $this->string($error->getMessage())->contains('Domain commercial supplier must belong');
            } finally {
                $manager->close();
            }
            $scope->assertActive();
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
            $this->integer((int)$connection->fetchOne('SELECT is_recursive FROM glpi_suppliers WHERE id = ?', [$supplier->getID()]))->isIdenticalTo(1);
            $this->integer((int)$connection->fetchOne('SELECT entities_id FROM glpi_suppliers WHERE id = ?', [$supplier->getID()]))->isIdenticalTo($source);
        }
        // Persist validates the initial assignment; PreFlush must revalidate
        // a new Domain after its Supplier changes later in the same unit of work.
        $pendingSupplier = $this->createItem(SupplierModel::class, [
            'name' => 'Pending commercial supplier ' . $this->getUniqueString(), 'entities_id' => $source, 'is_recursive' => 1,
        ]);
        $pendingName = 'Pending commercial domain ' . $this->getUniqueString();
        $manager = Orm::create($DB);
        try {
            $record = $manager->find(SupplierRecord::class, $pendingSupplier->getID());
            $pendingDomain = new DomainRecord();
            $pendingDomain->name = $pendingName;
            $pendingDomain->entities = $manager->getReference(EntityRecord::class, $child);
            $pendingDomain->suppliers = $record;
            $manager->persist($pendingDomain);
            $this->variable($pendingDomain->id)->isNull();
            $this->boolean($manager->getUnitOfWork()->isScheduledForInsert($pendingDomain))->isTrue();
            $record->is_recursive = false;
            $this->exception(static fn () => $manager->flush())->isInstanceOf(InvalidArgumentException::class)
                ->hasMessage('Domain commercial supplier must belong to its owner entity or a recursive ancestor.');
        } finally {
            $manager->close();
        }
        $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_domains WHERE name = ?', [$pendingName]))->isIdenticalTo(0);
        $this->integer((int)$connection->fetchOne('SELECT is_recursive FROM glpi_suppliers WHERE id = ?', [$pendingSupplier->getID()]))->isIdenticalTo(1);
        $scope->assertActive();
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        // Another public model can persist a Domain after this manager read the
        // Supplier and its then-empty incoming set, on the same physical writer.
        $lateSupplier = $this->createItem(SupplierModel::class, [
            'name' => 'Late commercial supplier ' . $this->getUniqueString(), 'entities_id' => $source, 'is_recursive' => 1,
        ]);
        $manager = Orm::create($DB);
        try {
            $record = $manager->find(SupplierRecord::class, $lateSupplier->getID());
            $this->array($manager->getRepository(DomainRecord::class)->findBy(['suppliers' => $record]))->isEmpty();
            $lateDomain = $this->createItem(Domain::class, [
                'name' => 'Late commercial domain ' . $this->getUniqueString(), 'entities_id' => $child,
                'suppliers_id' => $lateSupplier->getID(),
            ]);
            $record->is_recursive = false;
            $error = null;
            try {
                $manager->flush();
            } catch (InvalidArgumentException $caught) {
                $error = $caught;
            }
            $this->boolean($error instanceof InvalidArgumentException)->isTrue('Supplier validation must include Domains added by another model on its writer');
        } finally {
            $manager->close();
        }
        $this->integer((int)$connection->fetchOne('SELECT is_recursive FROM glpi_suppliers WHERE id = ?', [$lateSupplier->getID()]))->isIdenticalTo(1);
        $this->integer((int)$connection->fetchOne('SELECT suppliers_id FROM glpi_domains WHERE id = ?', [$lateDomain->getID()]))->isIdenticalTo((int)$lateSupplier->getID());
        $scope->assertActive();
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        // A coherent same-flush Domain reassignment must override persisted ownership.
        $replacement = $this->createItem(SupplierModel::class, [
            'name' => 'Replacement local supplier ' . $this->getUniqueString(), 'entities_id' => $child,
        ]);
        $manager = Orm::create($DB);
        try {
            $record = $manager->find(SupplierRecord::class, $supplier->getID());
            $manager->getRepository(DomainRecord::class)->findBy(['suppliers' => $record]);
            $managedDomain = $manager->find(DomainRecord::class, $domain->getID());
            $managedDomain->suppliers = $manager->getReference(SupplierRecord::class, $replacement->getID());
            $record->is_recursive = false;
            $manager->flush();
        } finally {
            $manager->close();
        }
        $this->integer((int)$connection->fetchOne('SELECT suppliers_id FROM glpi_domains WHERE id = ?', [$domain->getID()]))->isIdenticalTo((int)$replacement->getID());
        $this->integer((int)$connection->fetchOne('SELECT is_recursive FROM glpi_suppliers WHERE id = ?', [$supplier->getID()]))->isIdenticalTo(0);
        $scope->assertActive();
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
    }

    private function commercialDomainFixture(): array
    {
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $source = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $sibling = (int)getItemByTypeName('Entity', '_test_child_2', true);
        $supplier = $this->createItem(SupplierModel::class, [
            'name' => 'Commercial supplier ' . $this->getUniqueString(), 'entities_id' => $source, 'is_recursive' => 1,
        ]);
        $domain = $this->createItem(Domain::class, [
            'name' => 'Commercial domain ' . $this->getUniqueString(), 'entities_id' => $child,
            'suppliers_id' => $supplier->getID(),
        ]);
        return [$supplier, $domain, $source, $child, $sibling];
    }

    public function testGetLinksSanitizesOutput()
    {
        $obj = new SupplierModel();
        $obj->fields = [
           'id'      => 0,
           'name'    => '\'"<svg/onload=alert(1)>',
           'website' => "example.com' onclick='alert(1)",
        ];

        $links = $obj->getLinks(true);

        $this->string($links)
           ->contains("&lt;svg/onload=alert(1)&gt;")
           ->contains("href='http://example.com&#039; onclick=&#039;alert(1)'")
           ->notContains("<svg/onload=alert(1)>")
           ->notContains("href='http://example.com' onclick='alert(1)'");
    }
}
