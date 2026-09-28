<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_appliances_items')]
#[ORM\UniqueConstraint(name: 'appliances_items_unicity', columns: ['appliances_id', 'items_id', 'itemtype'])]
class ApplianceItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`appliances_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $appliances_id = 0;

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false, options: ['default' => ''])]
    public string $itemtype = '';
}
