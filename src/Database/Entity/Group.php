<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_groups')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_groups_name')]
#[SchemaIndex('ldap_field', ['ldap_field'], postgresqlName: 'glpi_groups_ldap_field')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_groups_entities_id')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_groups_date_mod')]
#[SchemaIndex('ldap_value', ['ldap_value'], options: ['lengths' => [200]], platform: AbstractMySQLPlatform::class)]
#[SchemaIndex('ldap_group_dn', ['ldap_group_dn'], options: ['lengths' => [200]], platform: AbstractMySQLPlatform::class)]
#[SchemaIndex('groups_id', ['groups_id'], postgresqlName: 'glpi_groups_groups_id')]
#[SchemaIndex('is_requester', ['is_requester'], postgresqlName: 'glpi_groups_is_requester')]
#[SchemaIndex('is_watcher', ['is_watcher'], postgresqlName: 'glpi_groups_is_watcher')]
#[SchemaIndex('is_assign', ['is_assign'], postgresqlName: 'glpi_groups_is_assign')]
#[SchemaIndex('is_notify', ['is_notify'], postgresqlName: 'glpi_groups_is_notify')]
#[SchemaIndex('is_itemgroup', ['is_itemgroup'], postgresqlName: 'glpi_groups_is_itemgroup')]
#[SchemaIndex('is_usergroup', ['is_usergroup'], postgresqlName: 'glpi_groups_is_usergroup')]
#[SchemaIndex('is_manager', ['is_manager'], postgresqlName: 'glpi_groups_is_manager')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_groups_date_creation')]
class Group
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_groups_entities_id', options: ['default' => '0'])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`ldap_field`', type: 'string', length: 255, nullable: true)]
    public ?string $ldap_field = null;

    #[ORM\Column(name: '`ldap_value`', type: 'text', nullable: true)]
    public ?string $ldap_value = null;

    #[ORM\Column(name: '`ldap_group_dn`', type: 'text', nullable: true)]
    public ?string $ldap_group_dn = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_groups_groups_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups = null;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', length: 4294967295, nullable: true)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => null])]
    public ?string $ancestors_cache = null;

    #[ORM\Column(name: '`sons_cache`', type: 'text', length: 4294967295, nullable: true)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['length' => null])]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`is_requester`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_requester = true;

    #[ORM\Column(name: '`is_watcher`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_watcher = true;

    #[ORM\Column(name: '`is_assign`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_assign = true;

    #[ORM\Column(name: '`is_task`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_task = true;

    #[ORM\Column(name: '`is_notify`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_notify = true;

    #[ORM\Column(name: '`is_itemgroup`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_itemgroup = true;

    #[ORM\Column(name: '`is_usergroup`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_usergroup = true;

    #[ORM\Column(name: '`is_manager`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_manager = true;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
