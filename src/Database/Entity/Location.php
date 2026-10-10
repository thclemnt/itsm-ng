<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKey;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_locations')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('locations_id', ['locations_id'], postgresqlName: 'glpi_locations_locations_id')]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_locations_name')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_locations_is_recursive')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_locations_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_locations_date_creation')]
#[SchemaIndex('glpi_locations_tree_entities', ['entities_id'])]
#[SchemaIndex('unicity', ['entities_id', 'parent_key', 'name'], unique: true, postgresqlName: 'glpi_locations_unicity')]
class Location
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_locations_entities_id')]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_locations_locations_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', nullable: true)]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['length' => 4294967295])]
    public ?string $ancestors_cache = null;

    #[ORM\Column(name: '`sons_cache`', type: 'text', nullable: true)]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['length' => 4294967295])]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`address`', type: 'text', nullable: true)]
    public ?string $address = null;

    #[ORM\Column(name: '`postcode`', type: 'string', length: 255, nullable: true)]
    public ?string $postcode = null;

    #[ORM\Column(name: '`town`', type: 'string', length: 255, nullable: true)]
    public ?string $town = null;

    #[ORM\Column(name: '`state`', type: 'string', length: 255, nullable: true)]
    public ?string $state = null;

    #[ORM\Column(name: '`country`', type: 'string', length: 255, nullable: true)]
    public ?string $country = null;

    #[ORM\Column(name: '`building`', type: 'string', length: 255, nullable: true)]
    public ?string $building = null;

    #[ORM\Column(name: '`room`', type: 'string', length: 255, nullable: true)]
    public ?string $room = null;

    #[ORM\Column(name: '`latitude`', type: 'string', length: 255, nullable: true)]
    public ?string $latitude = null;

    #[ORM\Column(name: '`longitude`', type: 'string', length: 255, nullable: true)]
    public ?string $longitude = null;

    #[ORM\Column(name: '`altitude`', type: 'string', length: 255, nullable: true)]
    public ?string $altitude = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
    #[ReferenceKey('locations_id')]
    #[ORM\Column(name: '`parent_key`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    public ?int $parent_key = null;
}
