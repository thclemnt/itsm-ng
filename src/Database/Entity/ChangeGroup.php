<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_changes_groups')]
#[ORM\UniqueConstraint(name: 'changes_groups_unicity', columns: ['changes_id', 'type', 'groups_id'])]
class ChangeGroup
{
    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ITILStatisticsRelation(ITILStatisticsRole::Groups)]
    #[ApplicationManaged]
    public ?Change $changes = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?Group $groups = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $type = 1;
}
