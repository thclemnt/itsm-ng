<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_slalevels_tickets')]
#[ORM\UniqueConstraint(name: 'slalevels_tickets_unicity', columns: ['tickets_id', 'slalevels_id'])]
class SlaLevelTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`tickets_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $tickets_id = 0;

    #[ORM\Column(name: '`slalevels_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $slalevels_id = 0;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date = null;
}
