<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_computermodels')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_computermodels_name')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_computermodels_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_computermodels_date_creation')]
#[SchemaIndex('product_number', ['product_number'], postgresqlName: 'glpi_computermodels_product_number')]
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
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_half_rack = false;

    #[ORM\Column(name: '`picture_front`', type: 'text', nullable: true)]
    public ?string $picture_front = null;

    #[ORM\Column(name: '`picture_rear`', type: 'text', nullable: true)]
    public ?string $picture_rear = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
