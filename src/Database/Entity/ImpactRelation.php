<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_impactrelations')]
#[ORM\UniqueConstraint(name: 'impactrelations_unicity', columns: ['itemtype_source', 'items_id_source', 'itemtype_impacted', 'items_id_impacted'])]
class ImpactRelation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype_source`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $itemtype_source = '';

    #[ORM\Column(name: '`items_id_source`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id_source = 0;

    #[ORM\Column(name: '`itemtype_impacted`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $itemtype_impacted = '';

    #[ORM\Column(name: '`items_id_impacted`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id_impacted = 0;
}
