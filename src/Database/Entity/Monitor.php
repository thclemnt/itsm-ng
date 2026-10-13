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
#[ORM\Table(name: 'glpi_monitors')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_monitors_name')]
#[SchemaIndex('is_template', ['is_template'], postgresqlName: 'glpi_monitors_is_template')]
#[SchemaIndex('is_global', ['is_global'], postgresqlName: 'glpi_monitors_is_global')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_monitors_entities_id')]
#[SchemaIndex('manufacturers_id', ['manufacturers_id'], postgresqlName: 'glpi_monitors_manufacturers_id')]
#[SchemaIndex('groups_id', ['groups_id'], postgresqlName: 'glpi_monitors_groups_id')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_monitors_users_id')]
#[SchemaIndex('locations_id', ['locations_id'], postgresqlName: 'glpi_monitors_locations_id')]
#[SchemaIndex('monitormodels_id', ['monitormodels_id'], postgresqlName: 'glpi_monitors_monitormodels_id')]
#[SchemaIndex('states_id', ['states_id'], postgresqlName: 'glpi_monitors_states_id')]
#[SchemaIndex('users_id_tech', ['users_id_tech'], postgresqlName: 'glpi_monitors_users_id_tech')]
#[SchemaIndex('monitortypes_id', ['monitortypes_id'], postgresqlName: 'glpi_monitors_monitortypes_id')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_monitors_is_deleted')]
#[SchemaIndex('groups_id_tech', ['groups_id_tech'], postgresqlName: 'glpi_monitors_groups_id_tech')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_monitors_is_dynamic')]
#[SchemaIndex('serial', ['serial'], postgresqlName: 'glpi_monitors_serial')]
#[SchemaIndex('otherserial', ['otherserial'], postgresqlName: 'glpi_monitors_otherserial')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_monitors_date_creation')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_monitors_is_recursive')]
class Monitor implements AllocationSubject
{
    use AllocationSubjectScope;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_monitors_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`contact`', type: 'string', length: 255, nullable: true)]
    public ?string $contact = null;

    #[ORM\Column(name: '`contact_num`', type: 'string', length: 255, nullable: true)]
    public ?string $contact_num = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_monitors_users_id_tech', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users_tech = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_monitors_groups_id_tech', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups_tech = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\Column(name: '`size`', type: 'decimal', precision: 5, scale: 2, nullable: false, options: ['default' => '0.00'])]
    public string $size = '0.00';

    #[ORM\Column(name: '`have_micro`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $have_micro = false;

    #[ORM\Column(name: '`have_speaker`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $have_speaker = false;

    #[ORM\Column(name: '`have_subd`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $have_subd = false;

    #[ORM\Column(name: '`have_bnc`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $have_bnc = false;

    #[ORM\Column(name: '`have_dvi`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $have_dvi = false;

    #[ORM\Column(name: '`have_pivot`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $have_pivot = false;

    #[ORM\Column(name: '`have_hdmi`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $have_hdmi = false;

    #[ORM\Column(name: '`have_displayport`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $have_displayport = false;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_monitors_locations_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\ManyToOne(targetEntity: MonitorType::class)]
    #[ORM\JoinColumn(name: 'monitortypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_monitors_monitortypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[AssetClassification]
    public ?MonitorType $monitortypes = null;

    #[RackModel]
    #[ORM\ManyToOne(targetEntity: MonitorModel::class)]
    #[ORM\JoinColumn(name: 'monitormodels_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_monitors_monitormodels_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?MonitorModel $monitormodels = null;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'manufacturers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_monitors_manufacturers_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Manufacturer $manufacturers = null;

    #[ORM\Column(name: '`is_global`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_global = false;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_template`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_template = false;

    #[ORM\Column(name: '`template_name`', type: 'string', length: 255, nullable: true)]
    public ?string $template_name = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_monitors_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_monitors_groups_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_monitors_states_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?State $states = null;

    #[ORM\Column(name: '`ticket_tco`', type: 'decimal', precision: 20, scale: 4, nullable: true, options: ['default' => '0.0000'])]
    public ?string $ticket_tco = '0.0000';

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;
}
