<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_specialstatuses')]
class SpecialStatus
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`weight`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $weight = 1;

    #[ORM\Column(name: '`is_active`', type: 'smallint', nullable: false, options: ['default' => '1'])]
    public int $is_active = 1;

    #[ORM\Column(name: '`color`', type: 'string', length: 255, nullable: true)]
    public ?string $color = null;
}
