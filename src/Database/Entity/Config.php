<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_configs')]
#[\itsmng\Database\Mapping\PlatformOptions(\Doctrine\DBAL\Platforms\AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[\itsmng\Database\Mapping\SchemaOwner]
#[\itsmng\Database\Mapping\SchemaIndex('unicity', ['context', 'name'], unique: true, postgresqlName: 'glpi_configs_unicity')]
class Config
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`context`', type: 'string', length: 150, nullable: true)]
    public ?string $context = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 150, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`value`', type: 'text', nullable: true)]
    public ?string $value = null;
}
