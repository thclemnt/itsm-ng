<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_impactitems')]
#[ORM\UniqueConstraint(name: 'impactitems_unicity', columns: ['itemtype', 'items_id'])]
class ImpactItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\ManyToOne(targetEntity: ImpactCompound::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ImpactCompound $compound = null;

    #[ORM\ManyToOne(targetEntity: ImpactContext::class)]
    #[ORM\JoinColumn(name: 'impactcontexts_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ImpactContext $context = null;

    #[ORM\Column(name: '`is_slave`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_slave = true;
}
