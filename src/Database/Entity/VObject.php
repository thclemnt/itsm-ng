<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\RequiredItemReference;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_vobjects')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['itemtype', 'items_id'], unique: true, postgresqlName: 'glpi_vobjects_unicity')]
#[SchemaIndex('item', ['itemtype', 'items_id'], postgresqlName: 'glpi_vobjects_item')]
#[SchemaIndex('date_mod', ['date_mod'], postgresqlName: 'glpi_vobjects_date_mod')]
#[SchemaIndex('date_creation', ['date_creation'], postgresqlName: 'glpi_vobjects_date_creation')]
#[SchemaIndex('glpi_vobjects_planningexternalevents_id', ['planningexternalevents_id'], postgresqlName: 'glpi_vobjects_planningexternalevents_id')]
#[SchemaIndex('glpi_vobjects_projecttasks_id', ['projecttasks_id'], postgresqlName: 'glpi_vobjects_projecttasks_id')]
#[SchemaIndex('glpi_vobjects_tickettasks_id', ['tickettasks_id'], postgresqlName: 'glpi_vobjects_tickettasks_id')]
#[SchemaIndex('glpi_vobjects_reminders_id', ['reminders_id'], postgresqlName: 'glpi_vobjects_reminders_id')]
#[SchemaIndex('glpi_vobjects_problemtasks_id', ['problemtasks_id'], postgresqlName: 'glpi_vobjects_problemtasks_id')]
#[SchemaIndex('glpi_vobjects_changetasks_id', ['changetasks_id'], postgresqlName: 'glpi_vobjects_changetasks_id')]
class VObject implements LegacyInput
{
    use RequiredItemReference;

    #[ORM\ManyToOne(targetEntity: PlanningExternalEvent::class)]
    #[ORM\JoinColumn(name: 'planningexternalevents_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_vobjects_planningexternalevents_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['PlanningExternalEvent'])]
    #[ApplicationManaged]
    public ?PlanningExternalEvent $externalEvent = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'projecttasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_vobjects_projecttasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ProjectTask'])]
    #[ApplicationManaged]
    public ?ProjectTask $projectTask = null;

    #[ORM\ManyToOne(targetEntity: TicketTask::class)]
    #[ORM\JoinColumn(name: 'tickettasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_vobjects_tickettasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['TicketTask'])]
    #[ApplicationManaged]
    public ?TicketTask $ticketTask = null;

    #[ORM\ManyToOne(targetEntity: Reminder::class)]
    #[ORM\JoinColumn(name: 'reminders_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_vobjects_reminders_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Reminder'])]
    #[ApplicationManaged]
    public ?Reminder $reminder = null;

    #[ORM\ManyToOne(targetEntity: ProblemTask::class)]
    #[ORM\JoinColumn(name: 'problemtasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_vobjects_problemtasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ProblemTask'])]
    #[ApplicationManaged]
    public ?ProblemTask $problemTask = null;

    #[ORM\ManyToOne(targetEntity: ChangeTask::class)]
    #[ORM\JoinColumn(name: 'changetasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_vobjects_changetasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ChangeTask'])]
    #[ApplicationManaged]
    public ?ChangeTask $changeTask = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`data`', type: 'text', nullable: true)]
    public ?string $data = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
