<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_oidc_config')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
class OidcConfig
{
    #[ORM\Id]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $id = 0;

    #[ORM\Column(name: '`Provider`', type: 'string', length: 255, nullable: true)]
    public ?string $Provider = null;

    #[ORM\Column(name: '`ClientID`', type: 'string', length: 255, nullable: true)]
    public ?string $ClientID = null;

    #[ORM\Column(name: '`ClientSecret`', type: 'string', length: 255, nullable: true)]
    public ?string $ClientSecret = null;

    #[ORM\Column(name: '`is_activate`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_activate = false;

    #[ORM\Column(name: '`is_forced`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_forced = false;

    #[ORM\Column(name: '`scope`', type: 'string', length: 255, nullable: true)]
    public ?string $scope = null;

    #[ORM\Column(name: '`proxy`', type: 'string', length: 255, nullable: true)]
    public ?string $proxy = null;

    #[ORM\Column(name: '`cert`', type: 'string', length: 255, nullable: true)]
    public ?string $cert = null;

    #[ORM\Column(name: '`sso_link_users`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $sso_link_users = true;

    #[ORM\Column(name: '`logout`', type: 'string', length: 255, nullable: true)]
    public ?string $logout = null;
}
