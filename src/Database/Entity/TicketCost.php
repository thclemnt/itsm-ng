<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\CostParent;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_ticketcosts')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_ticketcosts_name')]
#[SchemaIndex('tickets_id', ['tickets_id'], postgresqlName: 'glpi_ticketcosts_tickets_id')]
#[SchemaIndex('begin_date', ['begin_date'], postgresqlName: 'glpi_ticketcosts_begin_date')]
#[SchemaIndex('end_date', ['end_date'], postgresqlName: 'glpi_ticketcosts_end_date')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_ticketcosts_entities_id')]
#[SchemaIndex('budgets_id', ['budgets_id'], postgresqlName: 'glpi_ticketcosts_budgets_id')]
class TicketCost
{
    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_ticketcosts_tickets_id', options: ['default' => '0'])]
    #[CostParent]
    #[ApplicationManaged]
    public ?Ticket $tickets = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`begin_date`', type: 'date', nullable: true)]
    public ?DateTimeInterface $begin_date = null;

    #[ORM\Column(name: '`end_date`', type: 'date', nullable: true)]
    public ?DateTimeInterface $end_date = null;

    #[ORM\Column(name: '`actiontime`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $actiontime = 0;

    #[ORM\Column(name: '`cost_time`', type: 'decimal', precision: 20, scale: 4, nullable: false, options: ['default' => '0.0000'])]
    public string $cost_time = '0.0000';

    #[ORM\Column(name: '`cost_fixed`', type: 'decimal', precision: 20, scale: 4, nullable: false, options: ['default' => '0.0000'])]
    public string $cost_fixed = '0.0000';

    #[ORM\Column(name: '`cost_material`', type: 'decimal', precision: 20, scale: 4, nullable: false, options: ['default' => '0.0000'])]
    public string $cost_material = '0.0000';

    #[ORM\ManyToOne(targetEntity: Budget::class)]
    #[ORM\JoinColumn(name: 'budgets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_ticketcosts_budgets_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Budget $budgets = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_ticketcosts_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;
}
