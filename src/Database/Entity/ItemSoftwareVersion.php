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
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\ItemReference;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_items_softwareversions')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['itemtype', 'items_id', 'softwareversions_id'], unique: true, postgresqlName: 'glpi_items_softwareversions_unicity')]
#[SchemaIndex('items_id', ['items_id'], postgresqlName: 'glpi_items_softwareversions_items_id')]
#[SchemaIndex('itemtype', ['itemtype'], postgresqlName: 'glpi_items_softwareversions_itemtype')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_items_softwareversions_item')]
#[SchemaIndex('softwareversions_id', ['softwareversions_id'], postgresqlName: 'glpi_items_softwareversions_softwareversions_id')]
#[SchemaIndex('computers_info', ['entities_id', 'is_template_item', 'is_deleted_item'], postgresqlName: 'glpi_items_softwareversions_computers_info')]
#[SchemaIndex('is_template', ['is_template_item'], postgresqlName: 'glpi_items_softwareversions_is_template')]
#[SchemaIndex('is_deleted', ['is_deleted_item'], postgresqlName: 'glpi_items_softwareversions_is_deleted')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_items_softwareversions_is_dynamic')]
#[SchemaIndex('date_install', ['date_install'], postgresqlName: 'glpi_items_softwareversions_date_install')]
#[SchemaIndex('glpi_items_softwareversions_computers_id', ['computers_id'], postgresqlName: 'glpi_items_softwareversions_computers_id')]
#[SchemaIndex('glpi_items_softwareversions_monitors_id', ['monitors_id'], postgresqlName: 'glpi_items_softwareversions_monitors_id')]
#[SchemaIndex('glpi_items_softwareversions_networkequipments_id', ['networkequipments_id'], postgresqlName: 'glpi_items_softwareversions_networkequipments_id')]
#[SchemaIndex('glpi_items_softwareversions_peripherals_id', ['peripherals_id'], postgresqlName: 'glpi_items_softwareversions_peripherals_id')]
#[SchemaIndex('glpi_items_softwareversions_phones_id', ['phones_id'], postgresqlName: 'glpi_items_softwareversions_phones_id')]
#[SchemaIndex('glpi_items_softwareversions_printers_id', ['printers_id'], postgresqlName: 'glpi_items_softwareversions_printers_id')]
#[SchemaIndex('IDX_DE713330F4829AED', ['entities_id'], postgresqlName: 'IDX_DE713330F4829AED')]
class ItemSoftwareVersion implements LegacyInput
{
    use ItemReference;

    #[ORM\ManyToOne(targetEntity: SoftwareVersion::class)]
    #[ORM\JoinColumn(name: 'softwareversions_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_softwareversions_softwareversions_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?SoftwareVersion $softwareversions = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey(exactDiscriminator: true)]
    public ?int $items_id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_softwareversions_computers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[ApplicationManaged]
    public ?Computer $computer = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_softwareversions_monitors_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Monitor'])]
    #[ApplicationManaged]
    public ?Monitor $monitor = null;

    #[ORM\ManyToOne(targetEntity: NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_softwareversions_networkequipments_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'])]
    #[ApplicationManaged]
    public ?NetworkEquipment $networkEquipment = null;

    #[ORM\ManyToOne(targetEntity: Peripheral::class)]
    #[ORM\JoinColumn(name: 'peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_softwareversions_peripherals_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Peripheral'])]
    #[ApplicationManaged]
    public ?Peripheral $peripheral = null;

    #[ORM\ManyToOne(targetEntity: Phone::class)]
    #[ORM\JoinColumn(name: 'phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_softwareversions_phones_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Phone'])]
    #[ApplicationManaged]
    public ?Phone $phone = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_softwareversions_printers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Printer'])]
    #[ApplicationManaged]
    public ?Printer $printer = null;

    #[ORM\Column(name: '`is_deleted_item`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted_item = false;

    #[ORM\Column(name: '`is_template_item`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_template_item = false;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_softwareversions_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`date_install`', type: 'date', nullable: true)]
    public ?DateTimeInterface $date_install = null;
}
