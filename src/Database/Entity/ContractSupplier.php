<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_contracts_suppliers')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['suppliers_id', 'contracts_id'], unique: true, postgresqlName: 'glpi_contracts_suppliers_unicity')]
#[SchemaIndex('contracts_id', ['contracts_id'], postgresqlName: 'glpi_contracts_suppliers_contracts_id')]
#[SchemaIndex('IDX_78E4010422747B3C', ['suppliers_id'], postgresqlName: 'IDX_78E4010422747B3C')]
class ContractSupplier
{
    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'suppliers_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_suppliers_suppliers_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Supplier $suppliers = null;

    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(name: 'contracts_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_contracts_suppliers_contracts_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Contract $contracts = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;
}
