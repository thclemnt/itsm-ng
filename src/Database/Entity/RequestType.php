<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_requesttypes')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_requesttypes_name')]
#[SchemaIndex('is_helpdesk_default', ['is_helpdesk_default'], postgresqlName: 'glpi_requesttypes_is_helpdesk_default')]
#[SchemaIndex('is_followup_default', ['is_followup_default'], postgresqlName: 'glpi_requesttypes_is_followup_default')]
#[SchemaIndex('is_mail_default', ['is_mail_default'], postgresqlName: 'glpi_requesttypes_is_mail_default')]
#[SchemaIndex('is_mailfollowup_default', ['is_mailfollowup_default'], postgresqlName: 'glpi_requesttypes_is_mailfollowup_default')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_requesttypes_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_requesttypes_date_creation')]
#[SchemaIndex('is_active', ['is_active'], postgresqlName: 'glpi_requesttypes_is_active')]
#[SchemaIndex('is_ticketheader', ['is_ticketheader'], postgresqlName: 'glpi_requesttypes_is_ticketheader')]
#[SchemaIndex('is_itilfollowup', ['is_itilfollowup'], postgresqlName: 'glpi_requesttypes_is_itilfollowup')]
class RequestType
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`is_helpdesk_default`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_helpdesk_default = false;

    #[ORM\Column(name: '`is_followup_default`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_followup_default = false;

    #[ORM\Column(name: '`is_mail_default`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_mail_default = false;

    #[ORM\Column(name: '`is_mailfollowup_default`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_mailfollowup_default = false;

    #[ORM\Column(name: '`is_active`', type: 'smallint', nullable: false, options: ['default' => '1'])]
    public int $is_active = 1;

    #[ORM\Column(name: '`is_ticketheader`', type: 'smallint', nullable: false, options: ['default' => '1'])]
    public int $is_ticketheader = 1;

    #[ORM\Column(name: '`is_itilfollowup`', type: 'smallint', nullable: false, options: ['default' => '1'])]
    public int $is_itilfollowup = 1;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
