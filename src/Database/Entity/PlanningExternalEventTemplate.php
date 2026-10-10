<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_planningexternaleventtemplates')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('entities_id', ['entities_id'], postgresqlName: 'glpi_planningexternaleventtemplates_entities_id')]
#[SchemaIndex('state', ['state'], postgresqlName: 'glpi_planningexternaleventtemplates_state')]
#[SchemaIndex('planningeventcategories_id', ['planningeventcategories_id'], postgresqlName: 'glpi_planningexternaleventtemplates_planningeventcategories_id')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_planningexternaleventtemplates_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_planningexternaleventtemplates_date_creation')]
class PlanningExternalEventTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningexternaleventtemplates_entities_id', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`text`', type: 'text', nullable: true)]
    public ?string $text = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $duration = 0;

    #[ORM\Column(name: '`before_time`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $before_time = 0;

    #[ORM\Column(name: '`rrule`', type: 'text', nullable: true)]
    public ?string $rrule = null;

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $state = 0;

    #[ORM\ManyToOne(targetEntity: PlanningEventCategory::class)]
    #[ORM\JoinColumn(name: 'planningeventcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningexternaleventtemplates_planningeventcategories_id', options: ['default' => null])]
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
