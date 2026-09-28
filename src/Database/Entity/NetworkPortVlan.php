<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_networkports_vlans')]
#[ORM\UniqueConstraint(name: 'networkports_vlans_unicity', columns: ['networkports_id', 'vlans_id'])]
class NetworkPortVlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`networkports_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $networkports_id = 0;

    #[ORM\Column(name: '`vlans_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $vlans_id = 0;

    #[ORM\Column(name: '`tagged`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $tagged = false;
}
