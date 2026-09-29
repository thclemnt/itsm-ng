<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_itilfollowups')]
class ITILFollowup
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?User $author = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_editor', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?User $editor = null;

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $content = null;

    #[ORM\Column(name: '`is_private`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_private = false;

    #[ORM\ManyToOne(targetEntity: RequestType::class)]
    #[ORM\JoinColumn(name: 'requesttypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?RequestType $requesttypes = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`timeline_position`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $timeline_position = 0;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'sourceitems_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Ticket $sourceTicket = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'sourceof_items_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Ticket $promotedTicket = null;
}
