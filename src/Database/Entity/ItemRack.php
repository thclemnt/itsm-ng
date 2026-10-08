<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RackableItemReference;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_racks')]
#[ORM\UniqueConstraint(name: 'items_racks_item', columns: ['itemtype', 'items_id', 'is_reserved'])]
#[ORM\HasLifecycleCallbacks]
class ItemRack implements LegacyInput
{
    use RackableItemReference;

    #[ORM\ManyToOne(targetEntity: Rack::class)]
    #[ORM\JoinColumn(name: 'racks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?Rack $racks = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`position`', type: 'integer', nullable: false)]
    public int $position = 0;

    #[ORM\Column(name: '`orientation`', type: 'smallint', nullable: true)]
    public ?int $orientation = null;

    #[ORM\Column(name: '`bgcolor`', type: 'string', length: 7, nullable: true)]
    public ?string $bgcolor = null;

    #[ORM\Column(name: '`hpos`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $hpos = 0;

    #[ORM\Column(name: '`is_reserved`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_reserved = false;
}
