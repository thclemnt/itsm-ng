<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_pdus_racks')]
class PDURack
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`racks_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $racks_id = 0;

    #[ORM\Column(name: '`pdus_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $pdus_id = 0;

    #[ORM\Column(name: '`side`', type: 'integer', nullable: true, options: ['default' => '0'])]
    public ?int $side = null;

    #[ORM\Column(name: '`position`', type: 'integer', nullable: false)]
    public int $position = 0;

    #[ORM\Column(name: '`bgcolor`', type: 'string', length: 7, nullable: true)]
    public ?string $bgcolor = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
