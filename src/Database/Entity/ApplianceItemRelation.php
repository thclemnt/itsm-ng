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
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RequiredItemReference;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_appliances_items_relations')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('appliances_items_id', ['appliances_items_id'], postgresqlName: 'glpi_appliances_items_relations_appliances_items_id')]
#[SchemaIndex('itemtype', ['itemtype'], postgresqlName: 'glpi_appliances_items_relations_itemtype')]
#[SchemaIndex('items_id', ['items_id'], postgresqlName: 'glpi_appliances_items_relations_items_id')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_appliances_items_relations_item')]
#[SchemaIndex('glpi_appliances_items_relations_locations_id', ['locations_id'], postgresqlName: 'glpi_appliances_items_relations_locations_id')]
#[SchemaIndex('glpi_appliances_items_relations_networks_id', ['networks_id'], postgresqlName: 'glpi_appliances_items_relations_networks_id')]
#[SchemaIndex('glpi_appliances_items_relations_domains_id', ['domains_id'], postgresqlName: 'glpi_appliances_items_relations_domains_id')]
class ApplianceItemRelation implements LegacyInput
{
    use RequiredItemReference;
    #[ORM\ManyToOne(targetEntity: ApplianceItem::class)]
    #[ORM\JoinColumn(name: 'appliances_items_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_relations_appliances_items_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?ApplianceItem $appliances_items = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_relations_locations_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Location'])]
    #[ApplicationManaged]
    public ?Location $location = null;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'networks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_relations_networks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Network'])]
    #[ApplicationManaged]
    public ?Network $network = null;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(name: 'domains_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_relations_domains_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Domain'])]
    #[ApplicationManaged]
    public ?Domain $domain = null;

}
