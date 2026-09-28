<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_calendars_holidays')]
#[ORM\UniqueConstraint(name: 'calendars_holidays_unicity', columns: ['calendars_id', 'holidays_id'])]
class CalendarHoliday
{
    #[ORM\ManyToOne(targetEntity: Calendar::class)]
    #[ORM\JoinColumn(name: 'calendars_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?Calendar $calendars = null;

    #[ORM\ManyToOne(targetEntity: Holiday::class)]
    #[ORM\JoinColumn(name: 'holidays_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?Holiday $holidays = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;
}
