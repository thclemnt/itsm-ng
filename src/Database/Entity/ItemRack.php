<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RackableItemReference;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_racks')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(name: 'assetComputer', joinColumns: [new ORM\JoinColumn(name: 'asset_computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_racks_asset_computers_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'assetMonitor', joinColumns: [new ORM\JoinColumn(name: 'asset_monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_racks_asset_monitors_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'assetNetworkEquipment', joinColumns: [new ORM\JoinColumn(name: 'asset_networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_racks_asset_networkequipments_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'assetPeripheral', joinColumns: [new ORM\JoinColumn(name: 'asset_peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_racks_asset_peripherals_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'assetEnclosure', joinColumns: [new ORM\JoinColumn(name: 'asset_enclosures_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_racks_asset_enclosures_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'assetPdu', joinColumns: [new ORM\JoinColumn(name: 'asset_pdus_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_racks_asset_pdus_id', options: ['default' => null])]),
    new ORM\AssociationOverride(name: 'assetPassiveDCEquipment', joinColumns: [new ORM\JoinColumn(name: 'asset_passivedcequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_racks_asset_passivedcequipments_id', options: ['default' => null])])
])]
#[SchemaIndex('item', ['itemtype', 'items_id', 'is_reserved'], unique: true, postgresqlName: 'glpi_items_racks_item')]
#[SchemaIndex('relation', ['racks_id', 'itemtype', 'items_id'], postgresqlName: 'glpi_items_racks_relation')]
#[SchemaIndex('glpi_items_racks_asset_computers_id', ['asset_computers_id'], postgresqlName: 'glpi_items_racks_asset_computers_id')]
#[SchemaIndex('glpi_items_racks_asset_monitors_id', ['asset_monitors_id'], postgresqlName: 'glpi_items_racks_asset_monitors_id')]
#[SchemaIndex('glpi_items_racks_asset_networkequipments_id', ['asset_networkequipments_id'], postgresqlName: 'glpi_items_racks_asset_networkequipments_id')]
#[SchemaIndex('glpi_items_racks_asset_peripherals_id', ['asset_peripherals_id'], postgresqlName: 'glpi_items_racks_asset_peripherals_id')]
#[SchemaIndex('glpi_items_racks_asset_enclosures_id', ['asset_enclosures_id'], postgresqlName: 'glpi_items_racks_asset_enclosures_id')]
#[SchemaIndex('glpi_items_racks_asset_pdus_id', ['asset_pdus_id'], postgresqlName: 'glpi_items_racks_asset_pdus_id')]
#[SchemaIndex('glpi_items_racks_asset_passivedcequipments_id', ['asset_passivedcequipments_id'], postgresqlName: 'glpi_items_racks_asset_passivedcequipments_id')]
#[SchemaIndex('IDX_AE4B3C3EE2D342E', ['racks_id'], postgresqlName: 'IDX_AE4B3C3EE2D342E')]
#[ORM\HasLifecycleCallbacks]
class ItemRack implements LegacyInput
{
    use RackableItemReference;

    #[ORM\ManyToOne(targetEntity: Rack::class)]
    #[ORM\JoinColumn(name: 'racks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_racks_racks_id')]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    #[ApplicationManaged]
    public ?Rack $racks = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`position`', type: 'integer', nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    public int $position = 0;

    #[ORM\Column(name: '`orientation`', type: 'smallint', nullable: true)]
    public ?int $orientation = null;

    #[ORM\Column(name: '`bgcolor`', type: 'string', length: 7, nullable: true)]
    public ?string $bgcolor = null;

    #[ORM\Column(name: '`hpos`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $hpos = 0;

    #[ORM\Column(name: '`is_reserved`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_reserved = false;
}
