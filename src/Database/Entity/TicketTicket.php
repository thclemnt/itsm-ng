<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_tickets_tickets')]
#[ORM\UniqueConstraint(name: 'tickets_tickets_unicity', columns: ['tickets_id_1', 'tickets_id_2'])]
class TicketTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`tickets_id_1`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $tickets_id_1 = 0;

    #[ORM\Column(name: '`tickets_id_2`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $tickets_id_2 = 0;

    #[ORM\Column(name: '`link`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $link = 1;
}
