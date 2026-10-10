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
use itsmng\Database\Type\FixedStringType;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_rules')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_rules_entities_id')]
#[SchemaIndex('is_active', ['is_active'], postgresqlName: 'glpi_rules_is_active')]
#[SchemaIndex('sub_type', ['sub_type'], postgresqlName: 'glpi_rules_sub_type')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_rules_date_mod')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_rules_is_recursive')]
#[SchemaIndex('condition', ['condition'], postgresqlName: 'glpi_rules_condition')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_rules_date_creation')]
class Rule
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_rules_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`sub_type`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $sub_type = '';

    #[ORM\Column(name: '`ranking`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $ranking = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`description`', type: 'text', nullable: true)]
    public ?string $description = null;

    #[ORM\Column(name: '`match`', type: FixedStringType::NAME, length: 10, nullable: true, options: ['comment' => 'see define.php *_MATCHING constant'])]
    public ?string $match = null;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_active = true;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`uuid`', type: 'string', length: 255, nullable: true)]
    public ?string $uuid = null;

    #[ORM\Column(name: '`condition`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $condition = 0;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
