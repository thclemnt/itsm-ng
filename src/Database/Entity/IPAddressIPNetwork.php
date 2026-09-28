<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_ipaddresses_ipnetworks')]
#[ORM\UniqueConstraint(name: 'ipaddresses_ipnetworks_unicity', columns: ['ipaddresses_id', 'ipnetworks_id'])]
class IPAddressIPNetwork
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`ipaddresses_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $ipaddresses_id = 0;

    #[ORM\Column(name: '`ipnetworks_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $ipnetworks_id = 0;
}
