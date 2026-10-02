<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[\itsmng\Database\Mapping\RequiredSubjectConstraint('subject_kind')]
#[ORM\Table(name: 'glpi_itils_projects')]
#[ORM\UniqueConstraint(name: 'itils_projects_unicity', columns: ['itemtype', 'items_id', 'projects_id'])]
class ItilProject implements \itsmng\Database\Mapping\LegacyInput
{
    use \itsmng\Database\Mapping\ITILSubject;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?Project $projects = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

}
