<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_olalevelcriterias')]
class OlaLevelCriteria
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OlaLevel::class)]
    #[ORM\JoinColumn(name: 'olalevels_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?OlaLevel $olalevels = null;

    #[ORM\Column(name: '`criteria`', type: 'string', length: 255, nullable: true)]
    public ?string $criteria = null;

    #[ORM\Column(name: '`condition`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $condition = 0;

    #[ORM\Column(name: '`pattern`', type: 'string', length: 255, nullable: true)]
    public ?string $pattern = null;
}
