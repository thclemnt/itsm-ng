<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_ipnetworks_vlans')]
#[ORM\UniqueConstraint(name: 'ipnetworks_vlans_link', columns: ['ipnetworks_id', 'vlans_id'])]
class IPNetworkVlan
{
    #[ORM\ManyToOne(targetEntity: IPNetwork::class)]
    #[ORM\JoinColumn(name: 'ipnetworks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?IPNetwork $ipnetworks = null;

    #[ORM\ManyToOne(targetEntity: Vlan::class)]
    #[ORM\JoinColumn(name: 'vlans_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Vlan $vlans = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;
}
