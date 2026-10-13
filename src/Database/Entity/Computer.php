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
use itsmng\Database\Mapping\AllocationSubjectScope;
use itsmng\Database\Mapping\AssetClassification;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\RackModel;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Domain\AllocationSubject;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_computers')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_computers_date_mod')]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_computers_name')]
#[SchemaIndex('is_template', ['is_template'], postgresqlName: 'glpi_computers_is_template')]
#[SchemaIndex('autoupdatesystems_id', ['autoupdatesystems_id'], postgresqlName: 'glpi_computers_autoupdatesystems_id')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_computers_entities_id')]
#[SchemaIndex('manufacturers_id', ['manufacturers_id'], postgresqlName: 'glpi_computers_manufacturers_id')]
#[SchemaIndex('groups_id', ['groups_id'], postgresqlName: 'glpi_computers_groups_id')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_computers_users_id')]
#[SchemaIndex('locations_id', ['locations_id'], postgresqlName: 'glpi_computers_locations_id')]
#[SchemaIndex('computermodels_id', ['computermodels_id'], postgresqlName: 'glpi_computers_computermodels_id')]
#[SchemaIndex('networks_id', ['networks_id'], postgresqlName: 'glpi_computers_networks_id')]
#[SchemaIndex('states_id', ['states_id'], postgresqlName: 'glpi_computers_states_id')]
#[SchemaIndex('users_id_tech', ['users_id_tech'], postgresqlName: 'glpi_computers_users_id_tech')]
#[SchemaIndex('computertypes_id', ['computertypes_id'], postgresqlName: 'glpi_computers_computertypes_id')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_computers_is_deleted')]
#[SchemaIndex('groups_id_tech', ['groups_id_tech'], postgresqlName: 'glpi_computers_groups_id_tech')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_computers_is_dynamic')]
#[SchemaIndex('serial', ['serial'], postgresqlName: 'glpi_computers_serial')]
#[SchemaIndex('otherserial', ['otherserial'], postgresqlName: 'glpi_computers_otherserial')]
#[SchemaIndex('uuid', ['uuid'], postgresqlName: 'glpi_computers_uuid')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_computers_date_creation')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_computers_is_recursive')]
class Computer implements AllocationSubject
{
    use AllocationSubjectScope;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\Column(name: '`contact`', type: 'string', length: 255, nullable: true)]
    public ?string $contact = null;

    #[ORM\Column(name: '`contact_num`', type: 'string', length: 255, nullable: true)]
    public ?string $contact_num = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_users_id_tech', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users_tech = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_groups_id_tech', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups_tech = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\ManyToOne(targetEntity: AutoUpdateSystem::class)]
    #[ORM\JoinColumn(name: 'autoupdatesystems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_autoupdatesystems_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?AutoUpdateSystem $autoupdatesystems = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_locations_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'networks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_networks_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Network $networks = null;

    #[RackModel]
    #[ORM\ManyToOne(targetEntity: ComputerModel::class)]
    #[ORM\JoinColumn(name: 'computermodels_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_computermodels_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ComputerModel $computermodels = null;

    #[ORM\ManyToOne(targetEntity: ComputerType::class)]
    #[ORM\JoinColumn(name: 'computertypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_computertypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[AssetClassification]
    public ?ComputerType $computertypes = null;

    #[ORM\Column(name: '`is_template`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_template = false;

    #[ORM\Column(name: '`template_name`', type: 'string', length: 255, nullable: true)]
    public ?string $template_name = null;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'manufacturers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_manufacturers_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Manufacturer $manufacturers = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_dynamic = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_groups_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_states_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?State $states = null;

    #[ORM\Column(name: '`ticket_tco`', type: 'decimal', precision: 20, scale: 4, nullable: true, options: ['default' => '0.0000'])]
    public ?string $ticket_tco = '0.0000';

    #[ORM\Column(name: '`uuid`', type: 'string', length: 255, nullable: true)]
    public ?string $uuid = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;
}
