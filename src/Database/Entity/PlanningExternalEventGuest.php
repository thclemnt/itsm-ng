<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_planningexternaleventguests')]
#[ORM\UniqueConstraint(name: 'event_guest', columns: ['planningexternalevents_id', 'users_id'])]
#[ORM\UniqueConstraint(name: 'event_guest_position', columns: ['planningexternalevents_id', 'position'])]
class PlanningExternalEventGuest
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningExternalEvent::class)]
    #[ORM\JoinColumn(name: 'planningexternalevents_id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?PlanningExternalEvent $event = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?User $user = null;

    #[ORM\Column(type: 'integer')]
    public int $position = 0;
}
