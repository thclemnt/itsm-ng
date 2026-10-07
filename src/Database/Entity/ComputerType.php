<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_computertypes')]
#[\itsmng\Database\Mapping\PlatformOptions(\Doctrine\DBAL\Platforms\AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[\itsmng\Database\Mapping\SchemaOwner]
#[\itsmng\Database\Mapping\SchemaIndex('name', ['name'], postgresqlName: 'glpi_computertypes_name')]
#[\itsmng\Database\Mapping\SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_computertypes_date_mod')]
#[\itsmng\Database\Mapping\SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_computertypes_date_creation')]
class ComputerType
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $date_creation = null;
}
