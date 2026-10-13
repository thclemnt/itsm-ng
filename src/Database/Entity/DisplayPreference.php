<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_displaypreferences')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('rank', ['rank'], postgresqlName: 'glpi_displaypreferences_rank')]
#[SchemaIndex('num', ['num'], postgresqlName: 'glpi_displaypreferences_num')]
#[SchemaIndex('itemtype', ['itemtype'], postgresqlName: 'glpi_displaypreferences_itemtype')]
#[SchemaIndex('unicity', ['owner_key', 'itemtype', 'num'], unique: true, postgresqlName: 'glpi_displaypreferences_unicity')]
#[SchemaIndex('IDX_67F2BE713DB09D8', ['users_id'])]
class DisplayPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $itemtype = '';

    #[ORM\Column(name: '`num`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $num = 0;

    #[ORM\Column(name: '`rank`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $rank = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_displaypreferences_users_id')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?User $owner = null;

    #[ORM\Column(name: '`owner_key`', type: 'bigint', nullable: true, columnDefinition: 'BIGINT GENERATED ALWAYS AS (COALESCE(users_id, 0)) STORED', insertable: false, updatable: false, generated: 'ALWAYS')]
    public ?string $owner_key = null;
}
