<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_projecttasks')]
#[ORM\UniqueConstraint(name: 'projecttasks_uuid', columns: ['uuid'])]
class ProjectTask
{
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?Project $projects = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'projecttasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ProjectTask $projecttasks = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`uuid`', type: 'string', length: 255, nullable: true)]
    public ?string $uuid = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $content = null;

    #[ORM\Column(name: '`comment`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`plan_start_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $plan_start_date = null;

    #[ORM\Column(name: '`plan_end_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $plan_end_date = null;

    #[ORM\Column(name: '`real_start_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $real_start_date = null;

    #[ORM\Column(name: '`real_end_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $real_end_date = null;

    #[ORM\Column(name: '`planned_duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $planned_duration = 0;

    #[ORM\Column(name: '`effective_duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $effective_duration = 0;

    #[ORM\ManyToOne(targetEntity: ProjectState::class)]
    #[ORM\JoinColumn(name: 'projectstates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ProjectState $projectstates = null;

    #[ORM\ManyToOne(targetEntity: ProjectTaskType::class)]
    #[ORM\JoinColumn(name: 'projecttasktypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ProjectTaskType $projecttasktypes = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\Column(name: '`percent_done`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $percent_done = 0;

    #[ORM\Column(name: '`auto_percent_done`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $auto_percent_done = false;

    #[ORM\Column(name: '`is_milestone`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_milestone = false;

    #[ORM\ManyToOne(targetEntity: ProjectTaskTemplate::class)]
    #[ORM\JoinColumn(name: 'projecttasktemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ProjectTaskTemplate $projecttasktemplates = null;

    #[ORM\Column(name: '`is_template`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_template = false;

    #[ORM\Column(name: '`template_name`', type: 'string', length: 255, nullable: true)]
    public ?string $template_name = null;
}
