<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_impactcompounds')]
class ImpactCompound
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $name = '';

    #[ORM\Column(name: '`color`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $color = '';
}
