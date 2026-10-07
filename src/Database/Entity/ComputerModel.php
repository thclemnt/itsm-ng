<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_computermodels')]
#[\itsmng\Database\Mapping\PlatformOptions(\Doctrine\DBAL\Platforms\AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[\itsmng\Database\Mapping\SchemaOwner]
#[\itsmng\Database\Mapping\SchemaIndex('name', ['name'], postgresqlName: 'glpi_computermodels_name')]
#[\itsmng\Database\Mapping\SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_computermodels_date_mod')]
#[\itsmng\Database\Mapping\SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_computermodels_date_creation')]
#[\itsmng\Database\Mapping\SchemaIndex('product_number', ['product_number'], postgresqlName: 'glpi_computermodels_product_number')]
class ComputerModel
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`product_number`', type: 'string', length: 255, nullable: true)]
    public ?string $product_number = null;

    #[ORM\Column(name: '`weight`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $weight = 0;

    #[ORM\Column(name: '`required_units`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $required_units = 1;

    #[ORM\Column(name: '`depth`', type: 'float', nullable: false, options: ['default' => '1'])]
    public float $depth = 1.0;

    #[ORM\Column(name: '`power_connections`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $power_connections = 0;

    #[ORM\Column(name: '`power_consumption`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $power_consumption = 0;

    #[ORM\Column(name: '`is_half_rack`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[\itsmng\Database\Mapping\BooleanStorage(mysqlType: \Doctrine\DBAL\Types\Types::SMALLINT)]
    public bool $is_half_rack = false;

    #[ORM\Column(name: '`picture_front`', type: 'text', nullable: true)]
    public ?string $picture_front = null;

    #[ORM\Column(name: '`picture_rear`', type: 'text', nullable: true)]
    public ?string $picture_rear = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $date_creation = null;
}
