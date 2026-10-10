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
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_computerantiviruses')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_computerantiviruses_name')]
#[SchemaIndex('antivirus_version', ['antivirus_version'], postgresqlName: 'glpi_computerantiviruses_antivirus_version')]
#[SchemaIndex('signature_version', ['signature_version'], postgresqlName: 'glpi_computerantiviruses_signature_version')]
#[SchemaIndex('is_active', ['is_active'], postgresqlName: 'glpi_computerantiviruses_is_active')]
#[SchemaIndex('is_uptodate', ['is_uptodate'], postgresqlName: 'glpi_computerantiviruses_is_uptodate')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_computerantiviruses_is_dynamic')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_computerantiviruses_is_deleted')]
#[SchemaIndex('computers_id', ['computers_id'], postgresqlName: 'glpi_computerantiviruses_computers_id')]
#[SchemaIndex('date_expiration', ['date_expiration'], postgresqlName: 'glpi_computerantiviruses_date_expiration')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_computerantiviruses_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_computerantiviruses_date_creation')]
#[SchemaIndex('IDX_68671079714AFAD6', ['manufacturers_id'], postgresqlName: 'IDX_68671079714AFAD6')]
class ComputerAntivirus
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_computerantiviruses_computers_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Computer $computers = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'manufacturers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computerantiviruses_manufacturers_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Manufacturer $manufacturers = null;

    #[ORM\Column(name: '`antivirus_version`', type: 'string', length: 255, nullable: true)]
    public ?string $antivirus_version = null;

    #[ORM\Column(name: '`signature_version`', type: 'string', length: 255, nullable: true)]
    public ?string $signature_version = null;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_active = false;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_uptodate`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_uptodate = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`date_expiration`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_expiration = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
