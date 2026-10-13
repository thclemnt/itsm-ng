<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Type\FixedStringType;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_olalevels')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_olalevels_name')]
#[SchemaIndex('is_active', ['is_active'], postgresqlName: 'glpi_olalevels_is_active')]
#[SchemaIndex('olas_id', ['olas_id'], postgresqlName: 'glpi_olalevels_olas_id')]
#[SchemaIndex('IDX_EC99B26DF4829AED', ['entities_id'], postgresqlName: 'IDX_EC99B26DF4829AED')]
class OlaLevel
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\ManyToOne(targetEntity: OLA::class)]
    #[ORM\JoinColumn(name: 'olas_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_olalevels_olas_id', options: ['default' => '0'])]
    public ?OLA $olas = null;

    #[ORM\Column(name: '`execution_time`', type: 'integer', nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => 0])]
    public int $execution_time = 0;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_active = true;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_olalevels_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`match`', type: FixedStringType::NAME, length: 10, nullable: true, options: ['comment' => 'see define.php *_MATCHING constant'])]
    public ?string $match = null;

    #[ORM\Column(name: '`uuid`', type: 'string', length: 255, nullable: true)]
    public ?string $uuid = null;
}
