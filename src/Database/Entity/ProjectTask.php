<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_projecttasks')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('uuid', ['uuid'], unique: true, postgresqlName: 'glpi_projecttasks_uuid')]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_projecttasks_name')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_projecttasks_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_projecttasks_is_recursive')]
#[SchemaIndex('projects_id', ['projects_id'], postgresqlName: 'glpi_projecttasks_projects_id')]
#[SchemaIndex('projecttasks_id', ['projecttasks_id'], postgresqlName: 'glpi_projecttasks_projecttasks_id')]
#[SchemaIndex('date', ['date'], postgresqlName: 'glpi_projecttasks_date')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_projecttasks_date_mod')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_projecttasks_users_id')]
#[SchemaIndex('plan_start_date', ['plan_start_date'], postgresqlName: 'glpi_projecttasks_plan_start_date')]
#[SchemaIndex('plan_end_date', ['plan_end_date'], postgresqlName: 'glpi_projecttasks_plan_end_date')]
#[SchemaIndex('real_start_date', ['real_start_date'], postgresqlName: 'glpi_projecttasks_real_start_date')]
#[SchemaIndex('real_end_date', ['real_end_date'], postgresqlName: 'glpi_projecttasks_real_end_date')]
#[SchemaIndex('percent_done', ['percent_done'], postgresqlName: 'glpi_projecttasks_percent_done')]
#[SchemaIndex('projectstates_id', ['projectstates_id'], postgresqlName: 'glpi_projecttasks_projectstates_id')]
#[SchemaIndex('projecttasktypes_id', ['projecttasktypes_id'], postgresqlName: 'glpi_projecttasks_projecttasktypes_id')]
#[SchemaIndex('projecttasktemplates_id', ['projecttasktemplates_id'], postgresqlName: 'glpi_projecttasks_projecttasktemplates_id')]
#[SchemaIndex('is_template', ['is_template'], postgresqlName: 'glpi_projecttasks_is_template')]
#[SchemaIndex('is_milestone', ['is_milestone'], postgresqlName: 'glpi_projecttasks_is_milestone')]
class ProjectTask
{
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasks_projects_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?Project $projects = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'projecttasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasks_projecttasks_id', options: ['default' => null])]
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
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasks_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
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
    #[ORM\JoinColumn(name: 'projectstates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasks_projectstates_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ProjectState $projectstates = null;

    #[ORM\ManyToOne(targetEntity: ProjectTaskType::class)]
    #[ORM\JoinColumn(name: 'projecttasktypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasks_projecttasktypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ProjectTaskType $projecttasktypes = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasks_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\Column(name: '`percent_done`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $percent_done = 0;

    #[ORM\Column(name: '`auto_percent_done`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $auto_percent_done = false;

    #[ORM\Column(name: '`is_milestone`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_milestone = false;

    #[ORM\ManyToOne(targetEntity: ProjectTaskTemplate::class)]
    #[ORM\JoinColumn(name: 'projecttasktemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasks_projecttasktemplates_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ProjectTaskTemplate $projecttasktemplates = null;

    #[ORM\Column(name: '`is_template`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_template = false;

    #[ORM\Column(name: '`template_name`', type: 'string', length: 255, nullable: true)]
    public ?string $template_name = null;
}
