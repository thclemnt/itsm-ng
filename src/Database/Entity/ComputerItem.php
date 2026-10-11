<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\RequiredSubjectConstraint;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\DiscriminatorKey;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_computers_items')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[RequiredSubjectConstraint('parent_kind')]
#[ORM\HasLifecycleCallbacks]
#[SchemaIndex('computers_id', ['computers_id'], postgresqlName: 'glpi_computers_items_computers_id')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_computers_items_item')]
#[SchemaIndex('is_deleted', ['is_deleted'], postgresqlName: 'glpi_computers_items_is_deleted')]
#[SchemaIndex('is_dynamic', ['is_dynamic'], postgresqlName: 'glpi_computers_items_is_dynamic')]
#[SchemaIndex('glpi_computers_items_monitor_owner', ['monitors_id'])]
#[SchemaIndex('glpi_computers_items_peripheral_owner', ['peripherals_id'])]
#[SchemaIndex('glpi_computers_items_phone_owner', ['phones_id'])]
#[SchemaIndex('glpi_computers_items_printer_owner', ['printers_id'])]
class ComputerItem implements LegacyInput
{
    use OpenParentInput;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS', options: ['comment' => 'RELATION to various table, according to itemtype (ID)'])]
    #[DiscriminatorKey('opaque_parent_id', emptyValue: 0, exactDiscriminator: true, openStringFallback: true)]
    public int $items_id = 0;

    #[ORM\ManyToOne(targetEntity: Computer::class)]
    #[ORM\JoinColumn(name: 'computers_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_items_computers_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Computer $computers = null;

    #[ORM\ManyToOne(targetEntity: Monitor::class)]
    #[ORM\JoinColumn(name: 'monitors_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_items_monitors_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Monitor'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Monitor $monitor = null;

    #[ORM\ManyToOne(targetEntity: Peripheral::class)]
    #[ORM\JoinColumn(name: 'peripherals_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_items_peripherals_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Peripheral'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Peripheral $peripheral = null;

    #[ORM\ManyToOne(targetEntity: Phone::class)]
    #[ORM\JoinColumn(name: 'phones_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_items_phones_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Phone'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Phone $phone = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_computers_items_printers_id')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Printer'], emptyValue: 0)]
    #[ApplicationManaged]
    public ?Printer $printer = null;

    #[ORM\Column(name: '`opaque_parent_id`', type: 'bigint', nullable: true, options: ['default' => 0])]
    public ?int $opaque_parent_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    #[PlatformOptions(PostgreSQLPlatform::class, ['default' => ''])]
    public string $itemtype = '';

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_dynamic = false;
}
