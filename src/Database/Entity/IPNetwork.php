<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\NonNegative;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_ipnetworks')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('network_definition', ['entities_id', 'address', 'netmask'], postgresqlName: 'glpi_ipnetworks_network_definition')]
#[SchemaIndex('address', ['address_0', 'address_1', 'address_2', 'address_3'], postgresqlName: 'glpi_ipnetworks_address')]
#[SchemaIndex('netmask', ['netmask_0', 'netmask_1', 'netmask_2', 'netmask_3'], postgresqlName: 'glpi_ipnetworks_netmask')]
#[SchemaIndex('gateway', ['gateway_0', 'gateway_1', 'gateway_2', 'gateway_3'], postgresqlName: 'glpi_ipnetworks_gateway')]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_ipnetworks_name')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_ipnetworks_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_ipnetworks_date_creation')]
#[SchemaIndex('IDX_2D47D3C8F4829AED', ['entities_id'])]
#[SchemaIndex('IDX_2D47D3C8B0247248', ['ipnetworks_id'])]
class IPNetwork
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_ipnetworks_entities_id')]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_recursive = false;

    #[ORM\ManyToOne(targetEntity: IPNetwork::class)]
    #[ORM\JoinColumn(name: 'ipnetworks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_ipnetworks_ipnetworks_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?IPNetwork $parent = null;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $ancestors_cache = null;

    #[ORM\Column(name: '`sons_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`addressable`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $addressable = false;

    #[ORM\Column(name: '`version`', type: 'smallint', nullable: true, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_version_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['unsigned' => true])]
    public ?int $version = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`address`', type: 'string', length: 40, nullable: true)]
    public ?string $address = null;

    #[ORM\Column(name: '`address_0`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_address_0_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $address_0 = 0;

    #[ORM\Column(name: '`address_1`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_address_1_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $address_1 = 0;

    #[ORM\Column(name: '`address_2`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_address_2_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $address_2 = 0;

    #[ORM\Column(name: '`address_3`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_address_3_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $address_3 = 0;

    #[ORM\Column(name: '`netmask`', type: 'string', length: 40, nullable: true)]
    public ?string $netmask = null;

    #[ORM\Column(name: '`netmask_0`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_netmask_0_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $netmask_0 = 0;

    #[ORM\Column(name: '`netmask_1`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_netmask_1_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $netmask_1 = 0;

    #[ORM\Column(name: '`netmask_2`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_netmask_2_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $netmask_2 = 0;

    #[ORM\Column(name: '`netmask_3`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_netmask_3_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $netmask_3 = 0;

    #[ORM\Column(name: '`gateway`', type: 'string', length: 40, nullable: true)]
    public ?string $gateway = null;

    #[ORM\Column(name: '`gateway_0`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_gateway_0_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $gateway_0 = 0;

    #[ORM\Column(name: '`gateway_1`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_gateway_1_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $gateway_1 = 0;

    #[ORM\Column(name: '`gateway_2`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_gateway_2_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $gateway_2 = 0;

    #[ORM\Column(name: '`gateway_3`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    #[NonNegative('glpi_ipnetworks_gateway_3_check')]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['type' => 'integer', 'unsigned' => true])]
    public int $gateway_3 = 0;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
