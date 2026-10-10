<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\ItemReference;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKey;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_items_operatingsystems')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('items_id', ['items_id'], postgresqlName: 'glpi_items_operatingsystems_items_id')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_items_operatingsystems_item')]
#[SchemaIndex('operatingsystems_id', ['operatingsystems_id'], postgresqlName: 'glpi_items_operatingsystems_operatingsystems_id')]
#[SchemaIndex('operatingsystemservicepacks_id', ['operatingsystemservicepacks_id'], postgresqlName: 'glpi_items_operatingsystems_operatingsystemservicepacks_id')]
#[SchemaIndex('operatingsystemversions_id', ['operatingsystemversions_id'], postgresqlName: 'glpi_items_operatingsystems_operatingsystemversions_id')]
#[SchemaIndex('operatingsystemarchitectures_id', ['operatingsystemarchitectures_id'], postgresqlName: 'glpi_items_operatingsystems_operatingsystemarchitectures_id')]
#[SchemaIndex('operatingsystemkernelversions_id', ['operatingsystemkernelversions_id'], postgresqlName: 'glpi_items_operatingsystems_operatingsystemkernelversions_id')]
#[SchemaIndex('operatingsystemeditions_id', ['operatingsystemeditions_id'], postgresqlName: 'glpi_items_operatingsystems_operatingsystemeditions_id')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_items_operatingsystems_is_deleted')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_items_operatingsystems_is_dynamic')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_items_operatingsystems_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_items_operatingsystems_is_recursive')]
#[SchemaIndex('glpi_items_operatingsystems_computers_id', ['computers_id'])]
#[SchemaIndex('glpi_items_operatingsystems_monitors_id', ['monitors_id'])]
#[SchemaIndex('glpi_items_operatingsystems_networkequipments_id', ['networkequipments_id'])]
#[SchemaIndex('glpi_items_operatingsystems_peripherals_id', ['peripherals_id'])]
#[SchemaIndex('glpi_items_operatingsystems_phones_id', ['phones_id'])]
#[SchemaIndex('glpi_items_operatingsystems_printers_id', ['printers_id'])]
#[SchemaIndex('unicity', ['items_id', 'itemtype', 'operatingsystem_key', 'architecture_key'], unique: true, postgresqlName: 'glpi_items_operatingsystems_unicity')]
class ItemOperatingSystem implements LegacyInput
{
    use ItemReference;
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey(exactDiscriminator: true)]
    public ?int $items_id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: false)]
    public string $itemtype = '';

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_operatingsystems_computers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[ApplicationManaged]
    public ?Computer $computer = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_operatingsystems_monitors_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Monitor'])]
    #[ApplicationManaged]
    public ?Monitor $monitor = null;

    #[ORM\ManyToOne(targetEntity: NetworkEquipment::class)]
    #[ORM\JoinColumn(name: 'networkequipments_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_operatingsystems_networkequipments_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['NetworkEquipment'])]
    #[ApplicationManaged]
    public ?NetworkEquipment $networkEquipment = null;

    #[ORM\ManyToOne(targetEntity: Peripheral::class)]
    #[ORM\JoinColumn(name: 'peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_operatingsystems_peripherals_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Peripheral'])]
    #[ApplicationManaged]
    public ?Peripheral $peripheral = null;

    #[ORM\ManyToOne(targetEntity: Phone::class)]
    #[ORM\JoinColumn(name: 'phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_operatingsystems_phones_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Phone'])]
    #[ApplicationManaged]
    public ?Phone $phone = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_operatingsystems_printers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Printer'])]
    #[ApplicationManaged]
    public ?Printer $printer = null;

    #[ORM\ManyToOne(targetEntity: OperatingSystem::class)]
    #[ORM\JoinColumn(name: 'operatingsystems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_operatingsystems_operatingsystems_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OperatingSystem $operatingsystems = null;

    #[ORM\ManyToOne(targetEntity: OperatingSystemVersion::class)]
    #[ORM\JoinColumn(name: 'operatingsystemversions_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_operatingsystems_operatingsystemversions_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OperatingSystemVersion $operatingsystemversions = null;

    #[ORM\ManyToOne(targetEntity: OperatingSystemServicePack::class)]
    #[ORM\JoinColumn(name: 'operatingsystemservicepacks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_operatingsystems_operatingsystemservicepacks_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OperatingSystemServicePack $operatingsystemservicepacks = null;

    #[ORM\ManyToOne(targetEntity: OperatingSystemArchitecture::class)]
    #[ORM\JoinColumn(name: 'operatingsystemarchitectures_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_operatingsystems_operatingsystemarchitectures_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OperatingSystemArchitecture $operatingsystemarchitectures = null;

    #[ORM\ManyToOne(targetEntity: OperatingSystemKernelVersion::class)]
    #[ORM\JoinColumn(name: 'operatingsystemkernelversions_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_operatingsystems_operatingsystemkernelversions_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OperatingSystemKernelVersion $operatingsystemkernelversions = null;

    #[ORM\Column(name: '`license_number`', type: 'string', length: 255, nullable: true)]
    public ?string $license_number = null;

    #[ORM\Column(name: '`licenseid`', type: 'string', length: 255, nullable: true)]
    public ?string $licenseid = null;

    #[ORM\ManyToOne(targetEntity: OperatingSystemEdition::class)]
    #[ORM\JoinColumn(name: 'operatingsystemeditions_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_operatingsystems_operatingsystemeditions_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?OperatingSystemEdition $operatingsystemeditions = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_dynamic = false;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_items_operatingsystems_entities_id')]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_recursive = false;
    #[ORM\Column(name: 'operatingsystem_key', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[ReferenceKey('operatingsystems_id')]
    public ?int $operatingsystem_key = null;

    #[ORM\Column(name: 'architecture_key', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[ReferenceKey('operatingsystemarchitectures_id')]
    public ?int $architecture_key = null;

}
