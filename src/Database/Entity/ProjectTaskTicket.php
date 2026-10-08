<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_projecttasks_tickets')]
#[ORM\UniqueConstraint(name: 'projecttasks_tickets_unicity', columns: ['tickets_id', 'projecttasks_id'])]
class ProjectTaskTicket
{
    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?Ticket $tickets = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'projecttasks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?ProjectTask $projecttasks = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;
}
