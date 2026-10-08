<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\ITILSubject;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\RequiredSubjectConstraint;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[RequiredSubjectConstraint('subject_kind')]
#[ORM\Table(name: 'glpi_itils_projects')]
#[ORM\UniqueConstraint(name: 'itils_projects_unicity', columns: ['itemtype', 'items_id', 'projects_id'])]
class ItilProject implements LegacyInput
{
    use ITILSubject;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?Project $projects = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

}
