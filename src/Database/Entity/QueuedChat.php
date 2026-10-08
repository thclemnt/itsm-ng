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
#[ORM\Table(name: 'glpi_queuedchats')]
class QueuedChat
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\ManyToOne(targetEntity: NotificationTemplate::class)]
    #[ORM\JoinColumn(name: 'notificationtemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?NotificationTemplate $notificationtemplates = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    #[ReferencePolicy(ReferenceKind::RootEntity)]
    public ?Entity $entities = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Location $locations = null;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?Group $groups = null;

    #[ORM\ManyToOne(targetEntity: ITILCategory::class)]
    #[ORM\JoinColumn(name: 'itilcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    public ?ITILCategory $itilcategories = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`sent_try`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $sent_try = 0;

    #[ORM\Column(name: '`create_time`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $create_time = null;

    #[ORM\Column(name: '`send_time`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $send_time = null;

    #[ORM\Column(name: '`sent_time`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $sent_time = null;

    #[ORM\Column(name: '`entName`', type: 'text', nullable: true)]
    public ?string $entName = null;

    #[ORM\Column(name: '`ticketTitle`', type: 'text', nullable: true)]
    public ?string $ticketTitle = null;

    #[ORM\Column(name: '`completName`', type: 'text', nullable: true)]
    public ?string $completName = null;

    #[ORM\Column(name: '`serverName`', type: 'text', nullable: true)]
    public ?string $serverName = null;

    #[ORM\Column(name: '`hookurl`', type: 'string', length: 250, nullable: true)]
    public ?string $hookurl = null;

    #[ORM\Column(name: '`mode`', type: 'string', length: 20, nullable: false)]
    public string $mode = '';
}
