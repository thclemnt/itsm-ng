<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\UserReferenceAction;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_tickettasks')]
#[ORM\UniqueConstraint(name: 'tickettasks_uuid', columns: ['uuid'])]
class TicketTask
{
    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ITILStatisticsRelation(ITILStatisticsRole::Tasks)]
    #[ApplicationManaged]
    public ?Ticket $tickets = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`uuid`', type: 'string', length: 255, nullable: true)]
    public ?string $uuid = null;

    #[ORM\ManyToOne(targetEntity: TaskCategory::class)]
    #[ORM\JoinColumn(name: 'taskcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?TaskCategory $taskcategories = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $author = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_editor', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $editor = null;

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $content = null;

    #[ORM\Column(name: '`is_private`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_private = false;

    #[ORM\Column(name: '`actiontime`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $actiontime = 0;

    #[ORM\Column(name: '`begin`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $begin = null;

    #[ORM\Column(name: '`end`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $end = null;

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $state = 1;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection, userPurge: UserReferenceAction::ReassignHistory)]
    public ?User $technician = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups_tech = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;

    #[ORM\ManyToOne(targetEntity: TaskTemplate::class)]
    #[ORM\JoinColumn(name: 'tasktemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?TaskTemplate $tasktemplates = null;

    #[ORM\Column(name: '`timeline_position`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $timeline_position = 0;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'sourceitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Ticket $sourceTicket = null;
}
