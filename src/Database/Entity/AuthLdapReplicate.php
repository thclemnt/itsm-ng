<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_authldapreplicates')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('authldaps_id', ['authldaps_id'], postgresqlName: 'glpi_authldapreplicates_authldaps_id')]
class AuthLdapReplicate
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AuthLDAP::class)]
    #[ORM\JoinColumn(name: 'authldaps_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_authldapreplicates_authldaps_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public AuthLDAP $authldaps;

    #[ORM\Column(name: '`host`', type: 'string', length: 255, nullable: true)]
    public ?string $host = null;

    #[ORM\Column(name: '`port`', type: 'integer', nullable: false, options: ['default' => '389'])]
    public int $port = 389;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;
}
