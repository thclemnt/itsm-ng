<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_projects')]
class Project
{
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Project $projects = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`code`', type: 'string', length: 255, nullable: true)]
    public ?string $code = null;

    #[ORM\Column(name: '`priority`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $priority = 1;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\ManyToOne(targetEntity: ProjectState::class)]
    #[ORM\JoinColumn(name: 'projectstates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?ProjectState $projectstates = null;

    #[ORM\ManyToOne(targetEntity: ProjectType::class)]
    #[ORM\JoinColumn(name: 'projecttypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?ProjectType $projecttypes = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id = 0;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Group $groups = null;

    #[ORM\Column(name: '`plan_start_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $plan_start_date = null;

    #[ORM\Column(name: '`plan_end_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $plan_end_date = null;

    #[ORM\Column(name: '`real_start_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $real_start_date = null;

    #[ORM\Column(name: '`real_end_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $real_end_date = null;

    #[ORM\Column(name: '`percent_done`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $percent_done = 0;

    #[ORM\Column(name: '`auto_percent_done`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $auto_percent_done = false;

    #[ORM\Column(name: '`show_on_global_gantt`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $show_on_global_gantt = false;

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $content = null;

    #[ORM\Column(name: '`comment`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`projecttemplates_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $projecttemplates_id = 0;

    #[ORM\Column(name: '`is_template`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_template = false;

    #[ORM\Column(name: '`template_name`', type: 'string', length: 255, nullable: true)]
    public ?string $template_name = null;
}
