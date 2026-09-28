<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_slalevelactions')]
class SlaLevelAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`slalevels_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $slalevels_id = 0;

    #[ORM\Column(name: '`action_type`', type: 'string', length: 255, nullable: true)]
    public ?string $action_type = null;

    #[ORM\Column(name: '`field`', type: 'string', length: 255, nullable: true)]
    public ?string $field = null;

    #[ORM\Column(name: '`value`', type: 'string', length: 255, nullable: true)]
    public ?string $value = null;
}
