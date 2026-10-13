<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_savedsearches_users')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['users_id', 'itemtype'], unique: true, postgresqlName: 'glpi_savedsearches_users_unicity')]
#[SchemaIndex('savedsearches_id', ['savedsearches_id'], postgresqlName: 'glpi_savedsearches_users_savedsearches_id')]
#[SchemaIndex('IDX_6AB618A113DB09D8', ['users_id'], postgresqlName: 'IDX_6AB618A113DB09D8')]
class SavedSearchUser
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_savedsearches_users_users_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\ManyToOne(targetEntity: SavedSearch::class)]
    #[ORM\JoinColumn(name: 'savedsearches_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_savedsearches_users_savedsearches_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?SavedSearch $savedsearches = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $itemtype = '';
}
