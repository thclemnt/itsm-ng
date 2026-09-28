<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_cartridges')]
class Cartridge
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`cartridgeitems_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $cartridgeitems_id = 0;

    #[ORM\Column(name: '`printers_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $printers_id = 0;

    #[ORM\Column(name: '`date_in`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $date_in = null;

    #[ORM\Column(name: '`date_use`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $date_use = null;

    #[ORM\Column(name: '`date_out`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $date_out = null;

    #[ORM\Column(name: '`pages`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $pages = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
