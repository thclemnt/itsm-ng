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
#[ORM\Table(name: 'glpi_states')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_states_name')]
#[SchemaIndex('is_visible_computer', ['is_visible_computer'], postgresqlName: 'glpi_states_is_visible_computer')]
#[SchemaIndex('is_visible_monitor', ['is_visible_monitor'], postgresqlName: 'glpi_states_is_visible_monitor')]
#[SchemaIndex('is_visible_networkequipment', ['is_visible_networkequipment'], postgresqlName: 'glpi_states_is_visible_networkequipment')]
#[SchemaIndex('is_visible_peripheral', ['is_visible_peripheral'], postgresqlName: 'glpi_states_is_visible_peripheral')]
#[SchemaIndex('is_visible_phone', ['is_visible_phone'], postgresqlName: 'glpi_states_is_visible_phone')]
#[SchemaIndex('is_visible_printer', ['is_visible_printer'], postgresqlName: 'glpi_states_is_visible_printer')]
#[SchemaIndex('is_visible_softwareversion', ['is_visible_softwareversion'], postgresqlName: 'glpi_states_is_visible_softwareversion')]
#[SchemaIndex('is_visible_softwarelicense', ['is_visible_softwarelicense'], postgresqlName: 'glpi_states_is_visible_softwarelicense')]
#[SchemaIndex('is_visible_line', ['is_visible_line'], postgresqlName: 'glpi_states_is_visible_line')]
#[SchemaIndex('is_visible_certificate', ['is_visible_certificate'], postgresqlName: 'glpi_states_is_visible_certificate')]
#[SchemaIndex('is_visible_rack', ['is_visible_rack'], postgresqlName: 'glpi_states_is_visible_rack')]
#[SchemaIndex('is_visible_passivedcequipment', ['is_visible_passivedcequipment'], postgresqlName: 'glpi_states_is_visible_passivedcequipment')]
#[SchemaIndex('is_visible_enclosure', ['is_visible_enclosure'], postgresqlName: 'glpi_states_is_visible_enclosure')]
#[SchemaIndex('is_visible_pdu', ['is_visible_pdu'], postgresqlName: 'glpi_states_is_visible_pdu')]
#[SchemaIndex('is_visible_cluster', ['is_visible_cluster'], postgresqlName: 'glpi_states_is_visible_cluster')]
#[SchemaIndex('is_visible_contract', ['is_visible_contract'], postgresqlName: 'glpi_states_is_visible_contract')]
#[SchemaIndex('is_visible_appliance', ['is_visible_appliance'], postgresqlName: 'glpi_states_is_visible_appliance')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_states_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_states_date_creation')]
#[SchemaIndex('unicity', ['parent_key', 'name'], unique: true, postgresqlName: 'glpi_states_unicity')]
#[SchemaIndex('IDX_B329E15CF4829AED', ['entities_id'])]
#[SchemaIndex('IDX_B329E15CF104FBDD', ['states_id'])]
class State
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_states_entities_id')]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_states_states_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?State $states = null;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', nullable: true)]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['length' => 4294967295])]
    public ?string $ancestors_cache = null;

    #[ORM\Column(name: '`sons_cache`', type: 'text', nullable: true)]
    #[PlatformOptions(AbstractMySQLPlatform::class, ['length' => 4294967295])]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`is_visible_computer`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_computer = true;

    #[ORM\Column(name: '`is_visible_monitor`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_monitor = true;

    #[ORM\Column(name: '`is_visible_networkequipment`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_networkequipment = true;

    #[ORM\Column(name: '`is_visible_peripheral`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_peripheral = true;

    #[ORM\Column(name: '`is_visible_phone`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_phone = true;

    #[ORM\Column(name: '`is_visible_printer`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_printer = true;

    #[ORM\Column(name: '`is_visible_softwareversion`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_softwareversion = true;

    #[ORM\Column(name: '`is_visible_softwarelicense`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_softwarelicense = true;

    #[ORM\Column(name: '`is_visible_line`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_line = true;

    #[ORM\Column(name: '`is_visible_certificate`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_certificate = true;

    #[ORM\Column(name: '`is_visible_rack`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_rack = true;

    #[ORM\Column(name: '`is_visible_passivedcequipment`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_passivedcequipment = true;

    #[ORM\Column(name: '`is_visible_enclosure`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_enclosure = true;

    #[ORM\Column(name: '`is_visible_pdu`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_pdu = true;

    #[ORM\Column(name: '`is_visible_cluster`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_cluster = true;

    #[ORM\Column(name: '`is_visible_contract`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_contract = true;

    #[ORM\Column(name: '`is_visible_appliance`', type: 'boolean', nullable: false, options: ['default' => true])]
    #[BooleanStorage('smallint')]
    public bool $is_visible_appliance = true;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
    #[ReferenceKey('states_id')]
    #[ORM\Column(name: '`parent_key`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    public ?int $parent_key = null;
}
