<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\ProjectTeamMember;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_projectteams')]
#[ORM\UniqueConstraint(name: 'projectteams_unicity', columns: ['projects_id', 'itemtype', 'items_id'])]
class ProjectTeam implements LegacyInput
{
    use ProjectTeamMember;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?Project $projects = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

}
