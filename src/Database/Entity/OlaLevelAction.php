<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_olalevelactions')]
class OlaLevelAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OlaLevel::class)]
    #[ORM\JoinColumn(name: 'olalevels_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?OlaLevel $olalevels = null;

    #[ORM\Column(name: '`action_type`', type: 'string', length: 255, nullable: true)]
    public ?string $action_type = null;

    #[ORM\Column(name: '`field`', type: 'string', length: 255, nullable: true)]
    public ?string $field = null;

    #[ORM\Column(name: '`value`', type: 'string', length: 255, nullable: true)]
    public ?string $value = null;
}
