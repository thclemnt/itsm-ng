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
#[ORM\Table(name: 'glpi_documenttypes')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['ext'], unique: true, postgresqlName: 'glpi_documenttypes_unicity')]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_documenttypes_name')]
#[SchemaIndex('is_uploadable', ['is_uploadable'], postgresqlName: 'glpi_documenttypes_is_uploadable')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_documenttypes_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_documenttypes_date_creation')]
class DocumentType
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`ext`', type: 'string', length: 255, nullable: true)]
    public ?string $ext = null;

    #[ORM\Column(name: '`icon`', type: 'string', length: 255, nullable: true)]
    public ?string $icon = null;

    #[ORM\Column(name: '`mime`', type: 'string', length: 255, nullable: true)]
    public ?string $mime = null;

    #[ORM\Column(name: '`is_uploadable`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_uploadable = true;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
