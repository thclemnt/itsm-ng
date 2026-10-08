<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\ProjectTeamMember;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_projecttaskteams')]
#[ORM\UniqueConstraint(name: 'projecttaskteams_unicity', columns: ['projecttasks_id', 'itemtype', 'items_id'])]
class ProjectTaskTeam implements LegacyInput
{
    use ProjectTeamMember;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'projecttasks_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?ProjectTask $projecttasks = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

}
