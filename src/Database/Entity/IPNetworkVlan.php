<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_ipnetworks_vlans')]
#[ORM\UniqueConstraint(name: 'ipnetworks_vlans_link', columns: ['ipnetworks_id', 'vlans_id'])]
class IPNetworkVlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`ipnetworks_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $ipnetworks_id = 0;

    #[ORM\Column(name: '`vlans_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $vlans_id = 0;
}
