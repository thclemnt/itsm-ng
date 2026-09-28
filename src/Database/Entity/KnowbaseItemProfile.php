<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_knowbaseitems_profiles')]
class KnowbaseItemProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`knowbaseitems_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $knowbaseitems_id = 0;

    #[ORM\Column(name: '`profiles_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $profiles_id = 0;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '-1'])]
    public int $entities_id = -1;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;
}
