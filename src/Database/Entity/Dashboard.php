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
use itsmng\Database\Mapping\ReferenceKey;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_dashboards')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('dashboard_owners', ['profile_key', 'user_key'], unique: true)]
#[SchemaIndex('IDX_7331D49BE4B388C', ['profileId'])]
#[SchemaIndex('IDX_7331D4925A316AF', ['userId'])]
class Dashboard
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 100, nullable: false)]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'])]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $name = '';

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: false)]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'])]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => '', 'length' => null])]
    public string $content = '';

    #[ORM\ManyToOne(targetEntity: Profile::class)]
    #[ORM\JoinColumn(name: '`profileId`', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_dashboards_profileId')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?Profile $profile = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: '`userId`', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_dashboards_userId')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?User $owner = null;

    #[ReferenceKey('profileId')]
    #[ORM\Column(name: '`profile_key`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    public ?string $profile_key = null;

    #[ReferenceKey('userId')]
    #[ORM\Column(name: '`user_key`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    public ?string $user_key = null;
}
