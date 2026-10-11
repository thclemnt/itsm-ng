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
#[ORM\Table(name: 'glpi_items_disks')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_items_disks_name')]
#[SchemaIndex('device', ['device'], postgresqlName: 'glpi_items_disks_device')]
#[SchemaIndex('mountpoint', ['mountpoint'], postgresqlName: 'glpi_items_disks_mountpoint')]
#[SchemaIndex('totalsize', ['totalsize'], postgresqlName: 'glpi_items_disks_totalsize')]
#[SchemaIndex('freesize', ['freesize'], postgresqlName: 'glpi_items_disks_freesize')]
#[SchemaIndex('itemtype', ['itemtype'], postgresqlName: 'glpi_items_disks_itemtype')]
#[SchemaIndex('items_id', ['items_id'], postgresqlName: 'glpi_items_disks_items_id')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_items_disks_item')]
#[SchemaIndex('filesystems_id', ['filesystems_id'], postgresqlName: 'glpi_items_disks_filesystems_id')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_items_disks_entities_id')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_items_disks_is_deleted')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_items_disks_is_dynamic')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_items_disks_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_items_disks_date_creation')]
class ItemDisk
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_disks_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`device`', type: 'string', length: 255, nullable: true)]
    public ?string $device = null;

    #[ORM\Column(name: '`mountpoint`', type: 'string', length: 255, nullable: true)]
    public ?string $mountpoint = null;

    #[ORM\ManyToOne(targetEntity: Filesystem::class)]
    #[ORM\JoinColumn(name: 'filesystems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_disks_filesystems_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Filesystem $filesystems = null;

    #[ORM\Column(name: '`totalsize`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $totalsize = 0;

    #[ORM\Column(name: '`freesize`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $freesize = 0;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`encryption_status`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $encryption_status = 0;

    #[ORM\Column(name: '`encryption_tool`', type: 'string', length: 255, nullable: true)]
    public ?string $encryption_tool = null;

    #[ORM\Column(name: '`encryption_algorithm`', type: 'string', length: 255, nullable: true)]
    public ?string $encryption_algorithm = null;

    #[ORM\Column(name: '`encryption_type`', type: 'string', length: 255, nullable: true)]
    public ?string $encryption_type = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
