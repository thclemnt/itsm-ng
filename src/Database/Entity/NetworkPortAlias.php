<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkportaliases')]
#[ORM\UniqueConstraint(name: 'networkportaliases_networkports_id', columns: ['networkports_id'])]
class NetworkPortAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`networkports_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $networkports_id = 0;

    #[ORM\Column(name: '`networkports_id_alias`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $networkports_id_alias = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
