<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_pdus_plugs')]
class PduPlug
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`plugs_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $plugs_id = 0;

    #[ORM\Column(name: '`pdus_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $pdus_id = 0;

    #[ORM\Column(name: '`number_plugs`', type: 'integer', nullable: true, options: ['default' => '0'])]
    public ?int $number_plugs = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
