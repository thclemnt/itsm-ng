<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_planningexternalevents')]
#[ORM\UniqueConstraint(name: 'planningexternalevents_uuid', columns: ['uuid'])]
class PlanningExternalEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`uuid`', type: 'string', length: 255, nullable: true)]
    public ?string $uuid = null;

    #[ORM\ManyToOne(targetEntity: PlanningExternalEventTemplate::class)]
    #[ORM\JoinColumn(name: 'planningexternaleventtemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?PlanningExternalEventTemplate $planningexternaleventtemplates = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'smallint', nullable: false, options: ['default' => '1'])]
    public int $is_recursive = 1;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date = null;

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id = 0;

    #[ORM\Column(name: '`users_id_guests`', type: 'text', nullable: true)]
    public ?string $users_id_guests = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Group $groups = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`text`', type: 'text', nullable: true)]
    public ?string $text = null;

    #[ORM\Column(name: '`begin`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $begin = null;

    #[ORM\Column(name: '`end`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $end = null;

    #[ORM\Column(name: '`rrule`', type: 'text', nullable: true)]
    public ?string $rrule = null;

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $state = 0;

    #[ORM\ManyToOne(targetEntity: PlanningEventCategory::class)]
    #[ORM\JoinColumn(name: 'planningeventcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?PlanningEventCategory $planningeventcategories = null;

    #[ORM\Column(name: '`background`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $background = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
