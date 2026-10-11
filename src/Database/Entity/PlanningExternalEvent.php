<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_planningexternalevents')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('uuid', ['uuid'], unique: true, postgresqlName: 'glpi_planningexternalevents_uuid')]
#[SchemaIndex('planningexternaleventtemplates_id', ['planningexternaleventtemplates_id'], postgresqlName: 'glpi_planningexternalevents_planningexternaleventtemplates_id')]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_planningexternalevents_entities_id')]
#[SchemaIndex('is_recursive', ['is_recursive'], postgresqlName: 'glpi_planningexternalevents_is_recursive')]
#[SchemaIndex('date', ['date'], postgresqlName: 'glpi_planningexternalevents_date')]
#[SchemaIndex('begin', ['begin'], postgresqlName: 'glpi_planningexternalevents_begin')]
#[SchemaIndex('end', ['end'], postgresqlName: 'glpi_planningexternalevents_end')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_planningexternalevents_users_id')]
#[SchemaIndex('groups_id', ['groups_id'], postgresqlName: 'glpi_planningexternalevents_groups_id')]
#[SchemaIndex('state', ['state'], postgresqlName: 'glpi_planningexternalevents_state')]
#[SchemaIndex('planningeventcategories_id', ['planningeventcategories_id'], postgresqlName: 'glpi_planningexternalevents_planningeventcategories_id')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_planningexternalevents_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_planningexternalevents_date_creation')]
class PlanningExternalEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`uuid`', type: 'string', length: 255, nullable: true)]
    public ?string $uuid = null;

    #[ORM\ManyToOne(targetEntity: PlanningExternalEventTemplate::class)]
    #[ORM\JoinColumn(name: 'planningexternaleventtemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningexternalevents_planningexternaleventtemplates_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?PlanningExternalEventTemplate $planningexternaleventtemplates = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningexternalevents_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'smallint', nullable: false, options: ['default' => '1'])]
    public int $is_recursive = 1;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningexternalevents_users_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningexternalevents_groups_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`text`', type: 'text', nullable: true)]
    public ?string $text = null;

    #[ORM\Column(name: '`begin`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $begin = null;

    #[ORM\Column(name: '`end`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $end = null;

    #[ORM\Column(name: '`rrule`', type: 'text', nullable: true)]
    public ?string $rrule = null;

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $state = 0;

    #[ORM\ManyToOne(targetEntity: PlanningEventCategory::class)]
    #[ORM\JoinColumn(name: 'planningeventcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningexternalevents_planningeventcategories_id', options: ['default' => null])]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?PlanningEventCategory $planningeventcategories = null;

    #[ORM\Column(name: '`background`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $background = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
