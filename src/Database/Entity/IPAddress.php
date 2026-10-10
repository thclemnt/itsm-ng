<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_ipaddresses')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_ipaddresses_entities_id')]
#[SchemaIndex('textual', ['name'], postgresqlName: 'glpi_ipaddresses_textual')]
#[SchemaIndex('binary', ['binary_0', 'binary_1', 'binary_2', 'binary_3'], postgresqlName: 'glpi_ipaddresses_binary')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_ipaddresses_is_deleted')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_ipaddresses_is_dynamic')]
#[SchemaIndex('item', ['itemtype', 'items_id', 'is_deleted'], postgresqlName: 'glpi_ipaddresses_item')]
#[SchemaIndex('mainitem', ['mainitemtype', 'mainitems_id', 'is_deleted'], postgresqlName: 'glpi_ipaddresses_mainitem')]
class IPAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_ipaddresses_entities_id')]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $itemtype = '';

    #[ORM\Column(name: '`version`', type: 'smallint', nullable: true, options: ['default' => '0'])]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['unsigned' => true])]
    public ?int $version = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`binary_0`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $binary_0 = 0;

    #[ORM\Column(name: '`binary_1`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $binary_1 = 0;

    #[ORM\Column(name: '`binary_2`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $binary_2 = 0;

    #[ORM\Column(name: '`binary_3`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $binary_3 = 0;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`mainitems_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $mainitems_id = 0;

    #[ORM\Column(name: '`mainitemtype`', type: 'string', length: 255, nullable: true)]
    public ?string $mainitemtype = null;
}
