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
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_taskcategories')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_taskcategories_name')]
#[SchemaIndex('taskcategories_id', ['taskcategories_id'], postgresqlName: 'glpi_taskcategories_taskcategories_id')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_taskcategories_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_taskcategories_is_recursive')]
#[SchemaIndex('is_active', ['is_active'], postgresqlName: 'glpi_taskcategories_is_active')]
#[SchemaIndex('is_helpdeskvisible', ['is_helpdeskvisible'], postgresqlName: 'glpi_taskcategories_is_helpdeskvisible')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_taskcategories_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_taskcategories_date_creation')]
#[SchemaIndex('knowbaseitemcategories_id', ['knowbaseitemcategories_id'], postgresqlName: 'glpi_taskcategories_knowbaseitemcategories_id')]
class TaskCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_taskcategories_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\ManyToOne(targetEntity: TaskCategory::class)]
    #[ORM\JoinColumn(name: 'taskcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_taskcategories_taskcategories_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?TaskCategory $taskcategories = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $ancestors_cache = null;

    #[ORM\Column(name: '`sons_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_active = true;

    #[ORM\Column(name: '`is_helpdeskvisible`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_helpdeskvisible = true;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\ManyToOne(targetEntity: KnowbaseItemCategory::class)]
    #[ORM\JoinColumn(name: 'knowbaseitemcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_taskcategories_knowbaseitemcategories_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?KnowbaseItemCategory $knowbaseitemcategories = null;
}
