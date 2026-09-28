<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_ipaddresses_ipnetworks')]
#[ORM\UniqueConstraint(name: 'ipaddresses_ipnetworks_unicity', columns: ['ipaddresses_id', 'ipnetworks_id'])]
class IPAddressIPNetwork
{
    #[ORM\ManyToOne(targetEntity: IPAddress::class)]
    #[ORM\JoinColumn(name: 'ipaddresses_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?IPAddress $ipaddresses = null;

    #[ORM\ManyToOne(targetEntity: IPNetwork::class)]
    #[ORM\JoinColumn(name: 'ipnetworks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?IPNetwork $ipnetworks = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;
}
