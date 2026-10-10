<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\BooleanStorage;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_projecttasktemplates')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('name', ['name'], postgresqlName: 'glpi_projecttasktemplates_name')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_projecttasktemplates_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_projecttasktemplates_is_recursive')]
#[SchemaIndex('projects_id', ['projects_id'], postgresqlName: 'glpi_projecttasktemplates_projects_id')]
#[SchemaIndex('projecttasks_id', ['projecttasks_id'], postgresqlName: 'glpi_projecttasktemplates_projecttasks_id')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_projecttasktemplates_date_creation')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_projecttasktemplates_date_mod')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_projecttasktemplates_users_id')]
#[SchemaIndex('plan_start_date', ['plan_start_date'], postgresqlName: 'glpi_projecttasktemplates_plan_start_date')]
#[SchemaIndex('plan_end_date', ['plan_end_date'], postgresqlName: 'glpi_projecttasktemplates_plan_end_date')]
#[SchemaIndex('real_start_date', ['real_start_date'], postgresqlName: 'glpi_projecttasktemplates_real_start_date')]
#[SchemaIndex('real_end_date', ['real_end_date'], postgresqlName: 'glpi_projecttasktemplates_real_end_date')]
#[SchemaIndex('percent_done', ['percent_done'], postgresqlName: 'glpi_projecttasktemplates_percent_done')]
#[SchemaIndex('projectstates_id', ['projectstates_id'], postgresqlName: 'glpi_projecttasktemplates_projectstates_id')]
#[SchemaIndex('projecttasktypes_id', ['projecttasktypes_id'], postgresqlName: 'glpi_projecttasktemplates_projecttasktypes_id')]
#[SchemaIndex('is_milestone', ['is_milestone'], postgresqlName: 'glpi_projecttasktemplates_is_milestone')]
class ProjectTaskTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasktemplates_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`description`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $description = null;

    #[ORM\Column(name: '`comment`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'projects_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasktemplates_projects_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Project $projects = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'projecttasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasktemplates_projecttasks_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ProjectTask $projecttasks = null;

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
    #[ORM\JoinColumn(name: 'projectstates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasktemplates_projectstates_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ProjectState $projectstates = null;

    #[ORM\ManyToOne(targetEntity: ProjectTaskType::class)]
    #[ORM\JoinColumn(name: 'projecttasktypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasktemplates_projecttasktypes_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ProjectTaskType $projecttasktypes = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_projecttasktemplates_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\Column(name: '`percent_done`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $percent_done = 0;

    #[ORM\Column(name: '`is_milestone`', type: 'boolean', nullable: false, options: ['default' => false])]
    #[BooleanStorage(mysqlType: Types::SMALLINT)]
    public bool $is_milestone = false;

    #[ORM\Column(name: '`comments`', type: 'text', nullable: true)]
    public ?string $comments = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
