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
#[ORM\Table(name: 'glpi_planningrecalls')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['create_options' => [], 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'engine' => 'InnoDB'])]
#[SchemaOwner]
#[SchemaIndex('unicity', ['itemtype', 'items_id', 'users_id'], unique: true, postgresqlName: 'glpi_planningrecalls_unicity')]
#[SchemaIndex('users_id', ['users_id'], postgresqlName: 'glpi_planningrecalls_users_id')]
#[SchemaIndex('before_time', ['before_time'], postgresqlName: 'glpi_planningrecalls_before_time')]
#[SchemaIndex('when', ['when'], postgresqlName: 'glpi_planningrecalls_when')]
#[SchemaIndex('glpi_planningrecalls_planningexternalevents_id', ['planningexternalevents_id'], postgresqlName: 'glpi_planningrecalls_planningexternalevents_id')]
#[SchemaIndex('glpi_planningrecalls_projecttasks_id', ['projecttasks_id'], postgresqlName: 'glpi_planningrecalls_projecttasks_id')]
#[SchemaIndex('glpi_planningrecalls_tickettasks_id', ['tickettasks_id'], postgresqlName: 'glpi_planningrecalls_tickettasks_id')]
#[SchemaIndex('glpi_planningrecalls_reminders_id', ['reminders_id'], postgresqlName: 'glpi_planningrecalls_reminders_id')]
#[SchemaIndex('glpi_planningrecalls_problemtasks_id', ['problemtasks_id'], postgresqlName: 'glpi_planningrecalls_problemtasks_id')]
#[SchemaIndex('glpi_planningrecalls_changetasks_id', ['changetasks_id'], postgresqlName: 'glpi_planningrecalls_changetasks_id')]
class PlanningRecall implements LegacyInput
{
    use RequiredItemReference;

    #[ORM\ManyToOne(targetEntity: PlanningExternalEvent::class)]
    #[ORM\JoinColumn(name: 'planningexternalevents_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningrecalls_planningexternalevents_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['PlanningExternalEvent'])]
    #[ApplicationManaged]
    public ?PlanningExternalEvent $externalEvent = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'projecttasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningrecalls_projecttasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ProjectTask'])]
    #[ApplicationManaged]
    public ?ProjectTask $projectTask = null;

    #[ORM\ManyToOne(targetEntity: TicketTask::class)]
    #[ORM\JoinColumn(name: 'tickettasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningrecalls_tickettasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['TicketTask'])]
    #[ApplicationManaged]
    public ?TicketTask $ticketTask = null;

    #[ORM\ManyToOne(targetEntity: Reminder::class)]
    #[ORM\JoinColumn(name: 'reminders_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningrecalls_reminders_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['Reminder'])]
    #[ApplicationManaged]
    public ?Reminder $reminder = null;

    #[ORM\ManyToOne(targetEntity: ProblemTask::class)]
    #[ORM\JoinColumn(name: 'problemtasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningrecalls_problemtasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ProblemTask'])]
    #[ApplicationManaged]
    public ?ProblemTask $problemTask = null;

    #[ORM\ManyToOne(targetEntity: ChangeTask::class)]
    #[ORM\JoinColumn(name: 'changetasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningrecalls_changetasks_id', options: ['default' => null])]
    #[DiscriminatedBy('itemtype', 'items_id', ['ChangeTask'])]
    #[ApplicationManaged]
    public ?ChangeTask $changeTask = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_planningrecalls_users_id', options: ['default' => '0'])]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\Column(name: '`before_time`', type: 'integer', nullable: false, options: ['default' => '-10'])]
    public int $before_time = -10;

    #[ORM\Column(name: '`when`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $when = null;
}
