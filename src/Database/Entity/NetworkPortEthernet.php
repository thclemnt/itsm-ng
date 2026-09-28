<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkportethernets')]
#[ORM\UniqueConstraint(name: 'networkportethernets_networkports_id', columns: ['networkports_id'])]
class NetworkPortEthernet
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`networkports_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $networkports_id = 0;

    #[ORM\Column(name: '`items_devicenetworkcards_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_devicenetworkcards_id = 0;

    #[ORM\Column(name: '`netpoints_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $netpoints_id = 0;

    #[ORM\Column(name: '`type`', type: 'string', length: 10, nullable: true, options: ['default' => ''])]
    public ?string $type = null;

    #[ORM\Column(name: '`speed`', type: 'integer', nullable: false, options: ['default' => '10'])]
    public int $speed = 10;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
