<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Entity;

use atoum\atoum\test;
use InvalidArgumentException;
use itsmng\Database\Entity\Domain as DomainRecord;
use itsmng\Database\Entity\Entity as EntityRecord;
use itsmng\Database\Entity\Supplier as SupplierRecord;

class Domain extends test
{
    public function testCommercialSupplierOwnership(): void
    {
        $root = new EntityRecord();
        $root->id = 0;
        $child = new EntityRecord();
        $child->id = 2;
        $child->parent = $root;
        $supplier = new SupplierRecord();
        $supplier->entities = $root;
        $supplier->is_recursive = true;
        $domain = new DomainRecord();
        $domain->entities = $child;
        $domain->suppliers = $supplier;

        $this->variable($domain->assertCommercialSupplierOwnership())->isNull();
        $this->error()->withType(E_DEPRECATED)->withAnyMessage()->notExists();

        $supplier->entities = $child;
        $supplier->is_recursive = false;
        $this->variable($domain->assertCommercialSupplierOwnership())->isNull();

        $sameOwner = new EntityRecord();
        $sameOwner->id = $child->id;
        $supplier->entities = $sameOwner;
        $this->variable($domain->assertCommercialSupplierOwnership())->isNull();

        $supplier->entities = $root;
        $this->exception(fn () => $domain->assertCommercialSupplierOwnership())
            ->isInstanceOf(InvalidArgumentException::class)
            ->hasMessage('Domain commercial supplier must belong to its owner entity or a recursive ancestor.');

        $unrelated = new EntityRecord();
        $unrelated->id = 4;
        $supplier->entities = $unrelated;
        $supplier->is_recursive = true;
        $this->exception(fn () => $domain->assertCommercialSupplierOwnership())
            ->isInstanceOf(InvalidArgumentException::class)
            ->hasMessage('Domain commercial supplier must belong to its owner entity or a recursive ancestor.');

        $ancestor = new EntityRecord();
        $ancestor->id = 3;
        $ancestor->parent = $child;
        $child->parent = $ancestor;
        $this->exception(fn () => $domain->assertCommercialSupplierOwnership())
            ->isInstanceOf(InvalidArgumentException::class)
            ->hasMessage('Domain commercial supplier owner hierarchy contains a cycle.');
        $this->error()->withType(E_DEPRECATED)->withAnyMessage()->notExists();

        $ancestor->id = $child->id;
        $ancestor->parent = null;
        $this->exception(fn () => $domain->assertCommercialSupplierOwnership())
            ->isInstanceOf(InvalidArgumentException::class)
            ->hasMessage('Domain commercial supplier owner hierarchy contains a cycle.');
        $this->error()->withType(E_DEPRECATED)->withAnyMessage()->notExists();
    }
}
