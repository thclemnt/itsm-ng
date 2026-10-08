<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkports_vlans')]
#[ORM\UniqueConstraint(name: 'networkports_vlans_unicity', columns: ['networkports_id', 'vlans_id'])]
class NetworkPortVlan
{
    #[ORM\ManyToOne(targetEntity: NetworkPort::class)]
    #[ORM\JoinColumn(name: 'networkports_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?NetworkPort $networkports = null;

    #[ORM\ManyToOne(targetEntity: Vlan::class)]
    #[ORM\JoinColumn(name: 'vlans_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?Vlan $vlans = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`tagged`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $tagged = false;
}
