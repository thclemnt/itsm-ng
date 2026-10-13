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
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Domain\AllocationSubject;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_phones')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_phones_name')]
#[SchemaIndex('is_template', ['is_template'], postgresqlName: 'glpi_phones_is_template')]
#[SchemaIndex('is_global', ['is_global'], postgresqlName: 'glpi_phones_is_global')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_phones_entities_id')]
#[SchemaIndex('manufacturers_id', ['manufacturers_id'], postgresqlName: 'glpi_phones_manufacturers_id')]
#[SchemaIndex('groups_id', ['groups_id'], postgresqlName: 'glpi_phones_groups_id')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_phones_users_id')]
#[SchemaIndex('locations_id', ['locations_id'], postgresqlName: 'glpi_phones_locations_id')]
#[SchemaIndex('phonemodels_id', ['phonemodels_id'], postgresqlName: 'glpi_phones_phonemodels_id')]
#[SchemaIndex('phonepowersupplies_id', ['phonepowersupplies_id'], postgresqlName: 'glpi_phones_phonepowersupplies_id')]
#[SchemaIndex('states_id', ['states_id'], postgresqlName: 'glpi_phones_states_id')]
#[SchemaIndex('users_id_tech', ['users_id_tech'], postgresqlName: 'glpi_phones_users_id_tech')]
#[SchemaIndex('phonetypes_id', ['phonetypes_id'], postgresqlName: 'glpi_phones_phonetypes_id')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_phones_is_deleted')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_phones_date_mod')]
#[SchemaIndex('groups_id_tech', ['groups_id_tech'], postgresqlName: 'glpi_phones_groups_id_tech')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_phones_is_dynamic')]
#[SchemaIndex('serial', ['serial'], postgresqlName: 'glpi_phones_serial')]
#[SchemaIndex('otherserial', ['otherserial'], postgresqlName: 'glpi_phones_otherserial')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_phones_date_creation')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_phones_is_recursive')]
class Phone implements AllocationSubject
{
    use AllocationSubjectScope;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_phones_entities_id', options: ['default' => 0])]
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
    #[ORM\JoinColumn(name: 'users_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_phones_users_id_tech', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users_tech = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_phones_groups_id_tech', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups_tech = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_phones_locations_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\ManyToOne(targetEntity: PhoneType::class)]
    #[ORM\JoinColumn(name: 'phonetypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_phones_phonetypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[AssetClassification]
    public ?PhoneType $phonetypes = null;

    #[ORM\ManyToOne(targetEntity: PhoneModel::class)]
    #[ORM\JoinColumn(name: 'phonemodels_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_phones_phonemodels_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?PhoneModel $phonemodels = null;

    #[ORM\Column(name: '`brand`', type: 'string', length: 255, nullable: true)]
    public ?string $brand = null;

    #[ORM\ManyToOne(targetEntity: PhonePowerSupply::class)]
    #[ORM\JoinColumn(name: 'phonepowersupplies_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_phones_phonepowersupplies_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?PhonePowerSupply $phonepowersupplies = null;

    #[ORM\Column(name: '`number_line`', type: 'string', length: 255, nullable: true)]
    public ?string $number_line = null;

    #[ORM\Column(name: '`have_headset`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $have_headset = false;

    #[ORM\Column(name: '`have_hp`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $have_hp = false;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'manufacturers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_phones_manufacturers_id', options: ['default' => null])]
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
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_phones_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_phones_groups_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_phones_states_id', options: ['default' => null])]
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
