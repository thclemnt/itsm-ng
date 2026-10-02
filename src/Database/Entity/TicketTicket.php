<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_tickets_tickets')]
#[ORM\UniqueConstraint(name: 'tickets_tickets_unicity', columns: ['tickets_id_1', 'tickets_id_2'])]
class TicketTicket
{
    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id_1', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Ticket $tickets_id_1 = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id_2', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Ticket $tickets_id_2 = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`link`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $link = 1;
}
