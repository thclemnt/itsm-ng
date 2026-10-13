<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_plugins')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['directory'], unique: true, postgresqlName: 'glpi_plugins_unicity')]
#[SchemaIndex('state', ['state'], postgresqlName: 'glpi_plugins_state')]
class Plugin
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`directory`', type: 'string', length: 255, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $directory = '';

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $name = '';

    #[ORM\Column(name: '`version`', type: 'string', length: 255, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $version = '';

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false, options: ['default' => '0', 'comment' => 'see define.php PLUGIN_* constant'])]
    public int $state = 0;

    #[ORM\Column(name: '`author`', type: 'string', length: 255, nullable: true)]
    public ?string $author = null;

    #[ORM\Column(name: '`homepage`', type: 'string', length: 255, nullable: true)]
    public ?string $homepage = null;

    #[ORM\Column(name: '`license`', type: 'string', length: 255, nullable: true)]
    public ?string $license = null;
}
