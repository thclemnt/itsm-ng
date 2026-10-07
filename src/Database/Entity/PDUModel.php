<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_pdumodels')]
#[\itsmng\Database\Mapping\PlatformOptions(\Doctrine\DBAL\Platforms\AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[\itsmng\Database\Mapping\SchemaOwner]
#[\itsmng\Database\Mapping\SchemaIndex('name', ['name'], postgresqlName: 'glpi_pdumodels_name')]
#[\itsmng\Database\Mapping\SchemaIndex('is_rackable', ['is_rackable'], postgresqlName: 'glpi_pdumodels_is_rackable')]
#[\itsmng\Database\Mapping\SchemaIndex('product_number', ['product_number'], postgresqlName: 'glpi_pdumodels_product_number')]
class PDUModel
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

    #[ORM\Column(name: '`max_power`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $max_power = 0;

    #[ORM\Column(name: '`is_half_rack`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[\itsmng\Database\Mapping\BooleanStorage(mysqlType: \Doctrine\DBAL\Types\Types::SMALLINT)]
    public bool $is_half_rack = false;

    #[ORM\Column(name: '`picture_front`', type: 'text', nullable: true)]
    public ?string $picture_front = null;

    #[ORM\Column(name: '`picture_rear`', type: 'text', nullable: true)]
    public ?string $picture_rear = null;

    #[ORM\Column(name: '`is_rackable`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[\itsmng\Database\Mapping\BooleanStorage(mysqlType: \Doctrine\DBAL\Types\Types::SMALLINT)]
    public bool $is_rackable = false;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[\itsmng\Database\Mapping\NativeTimestamp]
    public ?\DateTimeInterface $date_creation = null;
}
