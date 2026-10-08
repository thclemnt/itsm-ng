<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_profiles_users')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_profiles_users_entities_id')]
#[SchemaIndex('profiles_id', ['profiles_id'], postgresqlName: 'glpi_profiles_users_profiles_id')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_profiles_users_users_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_profiles_users_is_recursive')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_profiles_users_is_dynamic')]
class ProfileUser
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_profiles_users_users_id', options: ['default' => 0])]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\ManyToOne(targetEntity: Profile::class)]
    #[ORM\JoinColumn(name: 'profiles_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_profiles_users_profiles_id', options: ['default' => 0])]
    #[ApplicationManaged]
    public ?Profile $profiles = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_profiles_users_entities_id', options: ['default' => 0])]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = true;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`is_default_profile`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_default_profile = false;
}
