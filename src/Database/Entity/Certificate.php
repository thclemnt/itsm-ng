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
use itsmng\Database\Mapping\AssetClassification;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_certificates')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_certificates_name')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_certificates_entities_id')]
#[SchemaIndex('is_template', ['is_template'], postgresqlName: 'glpi_certificates_is_template')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_certificates_is_deleted')]
#[SchemaIndex('certificatetypes_id', ['certificatetypes_id'], postgresqlName: 'glpi_certificates_certificatetypes_id')]
#[SchemaIndex('users_id_tech', ['users_id_tech'], postgresqlName: 'glpi_certificates_users_id_tech')]
#[SchemaIndex('groups_id_tech', ['groups_id_tech'], postgresqlName: 'glpi_certificates_groups_id_tech')]
#[SchemaIndex('groups_id', ['groups_id'], postgresqlName: 'glpi_certificates_groups_id')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_certificates_users_id')]
#[SchemaIndex('locations_id', ['locations_id'], postgresqlName: 'glpi_certificates_locations_id')]
#[SchemaIndex('manufacturers_id', ['manufacturers_id'], postgresqlName: 'glpi_certificates_manufacturers_id')]
#[SchemaIndex('states_id', ['states_id'], postgresqlName: 'glpi_certificates_states_id')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_certificates_date_creation')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_certificates_date_mod')]
class Certificate
{
    #[ORM\ManyToOne(targetEntity: CertificateType::class)]
    #[ORM\JoinColumn(name: 'certificatetypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_certificatetypes_id', options: ['comment' => 'RELATION to glpi_certificatetypes (id)'])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[AssetClassification]
    public ?CertificateType $certificatetypes = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_template`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_template = false;

    #[ORM\Column(name: '`template_name`', type: 'string', length: 255, nullable: true)]
    public ?string $template_name = null;

    #[ORM\Column(name: '`dns_name`', type: 'string', length: 255, nullable: true)]
    public ?string $dns_name = null;

    #[ORM\Column(name: '`dns_suffix`', type: 'string', length: 255, nullable: true)]
    public ?string $dns_suffix = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_users_id_tech', options: ['comment' => 'RELATION to glpi_users (id)'])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users_tech = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_groups_id_tech', options: ['comment' => 'RELATION to glpi_groups (id)'])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups_tech = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_locations_id', options: ['comment' => 'RELATION to glpi_locations (id)'])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'manufacturers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_manufacturers_id', options: ['comment' => 'RELATION to glpi_manufacturers (id)'])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Manufacturer $manufacturers = null;

    #[ORM\Column(name: '`contact`', type: 'string', length: 255, nullable: true)]
    public ?string $contact = null;

    #[ORM\Column(name: '`contact_num`', type: 'string', length: 255, nullable: true)]
    public ?string $contact_num = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?User $users = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_groups_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups = null;

    #[ORM\Column(name: '`is_autosign`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_autosign = false;

    #[ORM\Column(name: '`date_expiration`', type: 'date', nullable: true)]
    public ?DateTimeInterface $date_expiration = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_certificates_states_id', options: ['comment' => 'RELATION to states (id)'])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?State $states = null;

    #[ORM\Column(name: '`command`', type: 'text', nullable: true)]
    public ?string $command = null;

    #[ORM\Column(name: '`certificate_request`', type: 'text', nullable: true)]
    public ?string $certificate_request = null;

    #[ORM\Column(name: '`certificate_item`', type: 'text', nullable: true)]
    public ?string $certificate_item = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;
}
