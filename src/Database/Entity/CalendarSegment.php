<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_calendarsegments')]
class CalendarSegment
{
    #[ORM\ManyToOne(targetEntity: Calendar::class)]
    #[ORM\JoinColumn(name: 'calendars_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Calendar $calendars = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`day`', type: 'smallint', nullable: false, options: ['default' => '1'])]
    public int $day = 1;

    #[ORM\Column(name: '`begin`', type: 'itsm_clock_time', nullable: true)]
    public ?string $begin = null;

    #[ORM\Column(name: '`end`', type: 'itsm_clock_time', nullable: true)]
    public ?string $end = null;
}
