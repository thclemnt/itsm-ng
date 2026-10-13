<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_tickets_tickets')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['tickets_id_1', 'tickets_id_2'], unique: true, postgresqlName: 'glpi_tickets_tickets_unicity')]
#[SchemaIndex('IDX_C1295DC5A03EE8C1', ['tickets_id_1'], postgresqlName: 'IDX_C1295DC5A03EE8C1')]
#[SchemaIndex('IDX_C1295DC58B13BB02', ['tickets_id_2'], postgresqlName: 'IDX_C1295DC58B13BB02')]
class TicketTicket
{
    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id_1', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_tickets_tickets_id_1', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Ticket $tickets_id_1 = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id_2', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_tickets_tickets_tickets_id_2', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?Ticket $tickets_id_2 = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`link`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $link = 1;
}
