<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_cartridges')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('cartridgeitems_id', ['cartridgeitems_id'], postgresqlName: 'glpi_cartridges_cartridgeitems_id')]
#[SchemaIndex('printers_id', ['printers_id'], postgresqlName: 'glpi_cartridges_printers_id')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_cartridges_entities_id')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_cartridges_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_cartridges_date_creation')]
class Cartridge
{
    #[ORM\ManyToOne(targetEntity: CartridgeItem::class)]
    #[ORM\JoinColumn(name: 'cartridgeitems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_cartridges_cartridgeitems_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?CartridgeItem $cartridgeitems = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_cartridges_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_cartridges_printers_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?Printer $printers = null;

    #[ORM\Column(name: '`date_in`', type: 'date', nullable: true)]
    public ?DateTimeInterface $date_in = null;

    #[ORM\Column(name: '`date_use`', type: 'date', nullable: true)]
    public ?DateTimeInterface $date_use = null;

    #[ORM\Column(name: '`date_out`', type: 'date', nullable: true)]
    public ?DateTimeInterface $date_out = null;

    #[ORM\Column(name: '`pages`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $pages = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
