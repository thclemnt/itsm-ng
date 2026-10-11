<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RequiredSubjectConstraint;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_devicepcis')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[RequiredSubjectConstraint('parent_kind')]
#[ORM\HasLifecycleCallbacks]
#[SchemaIndex('glpi_items_devicepcis_computer_owner', ['computers_id'])]
#[SchemaIndex('computers_id', ['items_id'], postgresqlName: 'glpi_items_devicepcis_computers_id')]
#[SchemaIndex('devicepcis_id', ['devicepcis_id'], postgresqlName: 'glpi_items_devicepcis_devicepcis_id')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_items_devicepcis_is_deleted')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_items_devicepcis_is_dynamic')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_items_devicepcis_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_items_devicepcis_is_recursive')]
#[SchemaIndex('serial', ['serial'], postgresqlName: 'glpi_items_devicepcis_serial')]
#[SchemaIndex('busID', ['busID'], postgresqlName: 'glpi_items_devicepcis_busID')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_items_devicepcis_item')]
#[SchemaIndex('otherserial', ['otherserial'], postgresqlName: 'glpi_items_devicepcis_otherserial')]
#[SchemaIndex('locations_id', ['locations_id'], postgresqlName: 'glpi_items_devicepcis_locations_id')]
#[SchemaIndex('states_id', ['states_id'], postgresqlName: 'glpi_items_devicepcis_states_id')]
class ItemDevicePci implements LegacyInput
{
    use OpenComponentParent;

    #[ORM\ManyToOne(targetEntity: DevicePci::class)]
    #[ORM\JoinColumn(name: 'devicepcis_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_items_devicepcis_devicepcis_id')]
    public ?DevicePci $devicepcis = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey('opaque_parent_id', emptyValue: 0, exactDiscriminator: true, openStringFallback: true)]
    public int $items_id = 0;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_devicepcis_computers_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Computer'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Computer $computer = null;

    /** Stock and other extensible parent kinds retain their exact legacy identity. */
    #[ORM\Column(name: '`opaque_parent_id`', type: 'bigint', nullable: true, options: ['default' => 0])]
    public ?int $opaque_parent_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_dynamic = false;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_items_devicepcis_entities_id')]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`busID`', type: 'string', length: 255, nullable: true)]
    public ?string $busID = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_devicepcis_locations_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_devicepcis_states_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?State $states = null;
}
