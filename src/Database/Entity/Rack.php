<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_racks')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_racks_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_racks_is_recursive')]
#[SchemaIndex('locations_id', ['locations_id'], postgresqlName: 'glpi_racks_locations_id')]
#[SchemaIndex('rackmodels_id', ['rackmodels_id'], postgresqlName: 'glpi_racks_rackmodels_id')]
#[SchemaIndex('manufacturers_id', ['manufacturers_id'], postgresqlName: 'glpi_racks_manufacturers_id')]
#[SchemaIndex('racktypes_id', ['racktypes_id'], postgresqlName: 'glpi_racks_racktypes_id')]
#[SchemaIndex('states_id', ['states_id'], postgresqlName: 'glpi_racks_states_id')]
#[SchemaIndex('users_id_tech', ['users_id_tech'], postgresqlName: 'glpi_racks_users_id_tech')]
#[SchemaIndex('group_id_tech', ['groups_id_tech'], postgresqlName: 'glpi_racks_group_id_tech')]
#[SchemaIndex('is_template', ['is_template'], postgresqlName: 'glpi_racks_is_template')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_racks_is_deleted')]
#[SchemaIndex('dcrooms_id', ['dcrooms_id'], postgresqlName: 'glpi_racks_dcrooms_id')]
class Rack
{
    #[ORM\ManyToOne(targetEntity: RackType::class)]
    #[ORM\JoinColumn(name: 'racktypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_racks_racktypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?RackType $racktypes = null;

    #[ORM\ManyToOne(targetEntity: DCRoom::class)]
    #[ORM\JoinColumn(name: 'dcrooms_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_racks_dcrooms_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?DCRoom $dcrooms = null;

    #[ORM\ManyToOne(targetEntity: RackModel::class)]
    #[ORM\JoinColumn(name: 'rackmodels_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_racks_rackmodels_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?RackModel $rackmodels = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_racks_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_racks_locations_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'manufacturers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_racks_manufacturers_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Manufacturer $manufacturers = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_racks_states_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?State $states = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_racks_users_id_tech', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users_tech = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_racks_groups_id_tech', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups_tech = null;

    #[ORM\Column(name: '`width`', type: 'integer', nullable: true)]
    public ?int $width = null;

    #[ORM\Column(name: '`height`', type: 'integer', nullable: true)]
    public ?int $height = null;

    #[ORM\Column(name: '`depth`', type: 'integer', nullable: true)]
    public ?int $depth = null;

    #[ORM\Column(name: '`number_units`', type: 'integer', nullable: true, options: ['default' => '0'])]
    public ?int $number_units = 0;

    #[ORM\Column(name: '`is_template`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_template = false;

    #[ORM\Column(name: '`template_name`', type: 'string', length: 255, nullable: true)]
    public ?string $template_name = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`room_orientation`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $room_orientation = 0;

    #[ORM\Column(name: '`position`', type: 'string', length: 50, nullable: true)]
    public ?string $position = null;

    #[ORM\Column(name: '`bgcolor`', type: 'string', length: 7, nullable: true)]
    public ?string $bgcolor = null;

    #[ORM\Column(name: '`max_power`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $max_power = 0;

    #[ORM\Column(name: '`mesured_power`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $mesured_power = 0;

    #[ORM\Column(name: '`max_weight`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $max_weight = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
