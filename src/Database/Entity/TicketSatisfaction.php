<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_ticketsatisfactions')]
#[ORM\UniqueConstraint(name: 'ticketsatisfactions_tickets_id', columns: ['tickets_id'])]
class TicketSatisfaction
{
    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?Ticket $tickets = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $type = 1;

    #[ORM\Column(name: '`date_begin`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_begin = null;

    #[ORM\Column(name: '`date_answered`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_answered = null;

    #[ORM\Column(name: '`satisfaction`', type: 'integer', nullable: true)]
    public ?int $satisfaction = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;
}
