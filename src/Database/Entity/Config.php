<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_configs')]
#[ORM\UniqueConstraint(name: 'configs_unicity', columns: ['context', 'name'])]
class Config
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`context`', type: 'string', length: 150, nullable: true)]
    public ?string $context = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 150, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`value`', type: 'text', nullable: true)]
    public ?string $value = null;
}
