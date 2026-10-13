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
#[ORM\Table(name: 'glpi_appliances_items')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['appliances_id', 'items_id', 'itemtype'], unique: true, postgresqlName: 'glpi_appliances_items_unicity')]
#[SchemaIndex('appliances_id', ['appliances_id'], postgresqlName: 'glpi_appliances_items_appliances_id')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_appliances_items_item')]
#[SchemaIndex('glpi_appliances_items_computers_id', ['computers_id'], postgresqlName: 'glpi_appliances_items_computers_id')]
#[SchemaIndex('glpi_appliances_items_monitors_id', ['monitors_id'], postgresqlName: 'glpi_appliances_items_monitors_id')]
#[SchemaIndex('glpi_appliances_items_networkequipments_id', ['networkequipments_id'], postgresqlName: 'glpi_appliances_items_networkequipments_id')]
#[SchemaIndex('glpi_appliances_items_peripherals_id', ['peripherals_id'], postgresqlName: 'glpi_appliances_items_peripherals_id')]
#[SchemaIndex('glpi_appliances_items_phones_id', ['phones_id'], postgresqlName: 'glpi_appliances_items_phones_id')]
#[SchemaIndex('glpi_appliances_items_printers_id', ['printers_id'], postgresqlName: 'glpi_appliances_items_printers_id')]
#[SchemaIndex('glpi_appliances_items_softwares_id', ['softwares_id'], postgresqlName: 'glpi_appliances_items_softwares_id')]
#[SchemaIndex('glpi_appliances_items_clusters_id', ['clusters_id'], postgresqlName: 'glpi_appliances_items_clusters_id')]
class ApplianceItem implements LegacyInput
{
    use RequiredItemReference;
    #[ORM\ManyToOne(targetEntity: Appliance::class)]
    #[ORM\JoinColumn(name: 'appliances_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_appliances_id', options: ['default' => '0'])]
    public ?Appliance $appliances = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_computers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[ApplicationManaged]
    public ?Computer $computer = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_monitors_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Monitor'])]
    #[ApplicationManaged]
    public ?Monitor $monitor = null;

    #[ORM\ManyToOne(targetEntity: NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_networkequipments_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'])]
    #[ApplicationManaged]
    public ?NetworkEquipment $networkEquipment = null;

    #[ORM\ManyToOne(targetEntity: Peripheral::class)]
    #[ORM\JoinColumn(name: 'peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_peripherals_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Peripheral'])]
    #[ApplicationManaged]
    public ?Peripheral $peripheral = null;

    #[ORM\ManyToOne(targetEntity: Phone::class)]
    #[ORM\JoinColumn(name: 'phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_phones_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Phone'])]
    #[ApplicationManaged]
    public ?Phone $phone = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_printers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Printer'])]
    #[ApplicationManaged]
    public ?Printer $printer = null;

    #[ORM\ManyToOne(targetEntity: Software::class)]
    #[ORM\JoinColumn(name: 'softwares_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_softwares_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Software'])]
    #[ApplicationManaged]
    public ?Software $software = null;

    #[ORM\ManyToOne(targetEntity: Cluster::class)]
    #[ORM\JoinColumn(name: 'clusters_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_appliances_items_clusters_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Cluster'])]
    #[ApplicationManaged]
    public ?Cluster $cluster = null;

}
