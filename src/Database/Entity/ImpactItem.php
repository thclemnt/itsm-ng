<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_impactitems')]
#[ORM\UniqueConstraint(name: 'impactitems_unicity', columns: ['itemtype', 'items_id'])]
class ImpactItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`parent_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $parent_id = 0;

    #[ORM\Column(name: '`impactcontexts_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $impactcontexts_id = 0;

    #[ORM\Column(name: '`is_slave`', type: 'smallint', nullable: false, options: ['default' => '1'])]
    public int $is_slave = 1;
}
