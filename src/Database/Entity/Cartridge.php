<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_cartridges')]
class Cartridge
{
    #[ORM\ManyToOne(targetEntity: CartridgeItem::class)]
    #[ORM\JoinColumn(name: 'cartridgeitems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?CartridgeItem $cartridgeitems = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\ManyToOne(targetEntity: Printer::class)]
    #[ORM\JoinColumn(name: 'printers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Printer $printers = null;

    #[ORM\Column(name: '`date_in`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $date_in = null;

    #[ORM\Column(name: '`date_use`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $date_use = null;

    #[ORM\Column(name: '`date_out`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $date_out = null;

    #[ORM\Column(name: '`pages`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $pages = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
