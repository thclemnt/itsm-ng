<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkportwifis')]
#[ORM\UniqueConstraint(name: 'networkportwifis_networkports_id', columns: ['networkports_id'])]
class NetworkPortWifi
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`networkports_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $networkports_id = 0;

    #[ORM\Column(name: '`items_devicenetworkcards_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_devicenetworkcards_id = 0;

    #[ORM\Column(name: '`wifinetworks_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $wifinetworks_id = 0;

    #[ORM\Column(name: '`networkportwifis_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $networkportwifis_id = 0;

    #[ORM\Column(name: '`version`', type: 'string', length: 20, nullable: true)]
    public ?string $version = null;

    #[ORM\Column(name: '`mode`', type: 'string', length: 20, nullable: true)]
    public ?string $mode = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
