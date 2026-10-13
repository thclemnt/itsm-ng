<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_computervirtualmachines')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('computers_id', ['computers_id'], postgresqlName: 'glpi_computervirtualmachines_computers_id')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_computervirtualmachines_entities_id')]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_computervirtualmachines_name')]
#[SchemaIndex('virtualmachinestates_id', ['virtualmachinestates_id'], postgresqlName: 'glpi_computervirtualmachines_virtualmachinestates_id')]
#[SchemaIndex('virtualmachinesystems_id', ['virtualmachinesystems_id'], postgresqlName: 'glpi_computervirtualmachines_virtualmachinesystems_id')]
#[SchemaIndex('vcpu', ['vcpu'], postgresqlName: 'glpi_computervirtualmachines_vcpu')]
#[SchemaIndex('ram', ['ram'], postgresqlName: 'glpi_computervirtualmachines_ram')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_computervirtualmachines_is_deleted')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_computervirtualmachines_is_dynamic')]
#[SchemaIndex('uuid', ['uuid'], postgresqlName: 'glpi_computervirtualmachines_uuid')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_computervirtualmachines_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_computervirtualmachines_date_creation')]
#[SchemaIndex('IDX_6FDC320CFACD4096', ['virtualmachinetypes_id'])]
class ComputerVirtualMachine
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_computervirtualmachines_entities_id')]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0], foreignKeyName: 'fk_computervirtualmachines_computers_id')]
    public Computer $computers;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $name = '';

    #[ORM\ManyToOne(targetEntity: VirtualMachineState::class)]
    #[ORM\JoinColumn(name: 'virtualmachinestates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computervirtualmachines_virtualmachinestates_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?VirtualMachineState $virtualmachinestates = null;

    #[ORM\ManyToOne(targetEntity: VirtualMachineSystem::class)]
    #[ORM\JoinColumn(name: 'virtualmachinesystems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computervirtualmachines_virtualmachinesystems_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?VirtualMachineSystem $virtualmachinesystems = null;

    #[ORM\ManyToOne(targetEntity: VirtualMachineType::class)]
    #[ORM\JoinColumn(name: 'virtualmachinetypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computervirtualmachines_virtualmachinetypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?VirtualMachineType $virtualmachinetypes = null;

    #[ORM\Column(name: '`uuid`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $uuid = '';

    #[ORM\Column(name: '`vcpu`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $vcpu = 0;

    #[ORM\Column(name: '`ram`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $ram = '';

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage('smallint')]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
