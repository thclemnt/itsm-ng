<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_enclosures')]
#[ORM\UniqueConstraint(name: 'items_enclosures_item', columns: ['itemtype', 'items_id'])]
class ItemEnclosure
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`enclosures_id`', type: 'integer', nullable: false)]
    public int $enclosures_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false)]
    public int $items_id = 0;

    #[ORM\Column(name: '`position`', type: 'integer', nullable: false)]
    public int $position = 0;
}
