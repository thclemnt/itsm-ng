<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;
use itsmng\Database\Mapping\EntityScopeOwner;
use itsmng\Database\Mapping\ItemReference;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_items_deviceprocessors')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('computers_id', ['items_id'], postgresqlName: 'glpi_items_deviceprocessors_computers_id')]
#[SchemaIndex('deviceprocessors_id', ['deviceprocessors_id'], postgresqlName: 'glpi_items_deviceprocessors_deviceprocessors_id')]
#[SchemaIndex('specificity', ['frequency'], postgresqlName: 'glpi_items_deviceprocessors_specificity')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_items_deviceprocessors_is_deleted')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_items_deviceprocessors_is_dynamic')]
#[SchemaIndex('serial', ['serial'], postgresqlName: 'glpi_items_deviceprocessors_serial')]
#[SchemaIndex('nbcores', ['nbcores'], postgresqlName: 'glpi_items_deviceprocessors_nbcores')]
#[SchemaIndex('nbthreads', ['nbthreads'], postgresqlName: 'glpi_items_deviceprocessors_nbthreads')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_items_deviceprocessors_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_items_deviceprocessors_is_recursive')]
#[SchemaIndex('busID', ['busID'], postgresqlName: 'glpi_items_deviceprocessors_busID')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_items_deviceprocessors_item')]
#[SchemaIndex('otherserial', ['otherserial'], postgresqlName: 'glpi_items_deviceprocessors_otherserial')]
#[SchemaIndex('locations_id', ['locations_id'], postgresqlName: 'glpi_items_deviceprocessors_locations_id')]
#[SchemaIndex('states_id', ['states_id'], postgresqlName: 'glpi_items_deviceprocessors_states_id')]
#[SchemaIndex('glpi_items_deviceprocessors_computers_id', ['computers_id'], postgresqlName: 'glpi_items_deviceprocessors_computers_id_typed')]
class ItemDeviceProcessor implements LegacyInput
{
    use ItemReference;

    #[ORM\ManyToOne(targetEntity: DeviceProcessor::class)]
    #[ORM\JoinColumn(name: 'deviceprocessors_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_items_deviceprocessors_deviceprocessors_id')]
    #[EntityScopeOwner]
    public ?DeviceProcessor $deviceprocessors = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    #[DiscriminatorKey(emptyValue: 0, exactDiscriminator: true)]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_deviceprocessors_computers_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Computer'])]
    #[ApplicationManaged]
    public ?Computer $computer = null;

    #[ORM\Column(name: '`frequency`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $frequency = 0;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`nbcores`', type: 'integer', nullable: true)]
    public ?int $nbcores = null;

    #[ORM\Column(name: '`nbthreads`', type: 'integer', nullable: true)]
    public ?int $nbthreads = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_items_deviceprocessors_entities_id')]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`busID`', type: 'string', length: 255, nullable: true)]
    public ?string $busID = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_deviceprocessors_locations_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_items_deviceprocessors_states_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?State $states = null;

}
