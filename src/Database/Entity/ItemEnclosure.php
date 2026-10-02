<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_enclosures')]
#[ORM\UniqueConstraint(name: 'items_enclosures_item', columns: ['itemtype', 'items_id'])]
#[ORM\HasLifecycleCallbacks]
class ItemEnclosure implements \itsmng\Database\Mapping\LegacyInput
{
    use \itsmng\Database\Mapping\RackableItemReference;

    #[ORM\ManyToOne(targetEntity: Enclosure::class)]
    #[ORM\JoinColumn(name: 'enclosures_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Enclosure $enclosures = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`position`', type: 'integer', nullable: false)]
    public int $position = 0;
}
