<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_olalevels_tickets')]
#[ORM\UniqueConstraint(name: 'olalevels_tickets_unicity', columns: ['tickets_id', 'olalevels_id'])]
class OlaLevelTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`tickets_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $tickets_id = 0;

    #[ORM\Column(name: '`olalevels_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $olalevels_id = 0;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date = null;
}
