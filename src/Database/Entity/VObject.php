<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\DiscriminatedBy;
use itsmng\Database\Mapping\LegacyInput;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\RequiredItemReference;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'glpi_vobjects')]
#[ORM\UniqueConstraint(name: 'vobjects_unicity', columns: ['itemtype', 'items_id'])]
class VObject implements LegacyInput
{
    use RequiredItemReference;

    #[ORM\ManyToOne(targetEntity: PlanningExternalEvent::class)]
    #[ORM\JoinColumn(name: 'planningexternalevents_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['PlanningExternalEvent'])]
    #[ApplicationManaged]
    public ?PlanningExternalEvent $externalEvent = null;

    #[ORM\ManyToOne(targetEntity: ProjectTask::class)]
    #[ORM\JoinColumn(name: 'projecttasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['ProjectTask'])]
    #[ApplicationManaged]
    public ?ProjectTask $projectTask = null;

    #[ORM\ManyToOne(targetEntity: TicketTask::class)]
    #[ORM\JoinColumn(name: 'tickettasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['TicketTask'])]
    #[ApplicationManaged]
    public ?TicketTask $ticketTask = null;

    #[ORM\ManyToOne(targetEntity: Reminder::class)]
    #[ORM\JoinColumn(name: 'reminders_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['Reminder'])]
    #[ApplicationManaged]
    public ?Reminder $reminder = null;

    #[ORM\ManyToOne(targetEntity: ProblemTask::class)]
    #[ORM\JoinColumn(name: 'problemtasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[DiscriminatedBy('itemtype', 'items_id', ['ProblemTask'])]
    #[ApplicationManaged]
    public ?ProblemTask $problemTask = null;

    #[ORM\ManyToOne(targetEntity: ChangeTask::class)]
    #[ORM\JoinColumn(name: 'changetasks_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
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
