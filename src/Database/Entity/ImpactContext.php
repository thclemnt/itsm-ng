<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_impactcontexts')]
class ImpactContext
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`positions`', type: 'text', nullable: false)]
    public string $positions = '';

    #[ORM\Column(name: '`zoom`', type: 'float', nullable: false, options: ['default' => '0'])]
    public float $zoom = 0.0;

    #[ORM\Column(name: '`pan_x`', type: 'float', nullable: false, options: ['default' => '0'])]
    public float $pan_x = 0.0;

    #[ORM\Column(name: '`pan_y`', type: 'float', nullable: false, options: ['default' => '0'])]
    public float $pan_y = 0.0;

    #[ORM\Column(name: '`impact_color`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $impact_color = '';

    #[ORM\Column(name: '`depends_color`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $depends_color = '';

    #[ORM\Column(name: '`impact_and_depends_color`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $impact_and_depends_color = '';

    #[ORM\Column(name: '`show_depends`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $show_depends = true;

    #[ORM\Column(name: '`show_impact`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $show_impact = true;

    #[ORM\Column(name: '`max_depth`', type: 'integer', nullable: false, options: ['default' => '5'])]
    public int $max_depth = 5;
}
