<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_slalevels_tickets')]
#[ORM\UniqueConstraint(name: 'slalevels_tickets_unicity', columns: ['tickets_id', 'slalevels_id'])]
class SlaLevelTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?Ticket $tickets = null;

    #[ORM\ManyToOne(targetEntity: SlaLevel::class)]
    #[ORM\JoinColumn(name: 'slalevels_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?SlaLevel $slalevels = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;
}
