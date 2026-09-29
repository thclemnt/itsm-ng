<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_ticketrecurrents')]
class TicketRecurrent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_active = false;

    #[ORM\ManyToOne(targetEntity: TicketTemplate::class)]
    #[ORM\JoinColumn(name: 'tickettemplates_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?TicketTemplate $tickettemplates_id = null;

    #[ORM\Column(name: '`begin_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $begin_date = null;

    #[ORM\Column(name: '`periodicity`', type: 'string', length: 255, nullable: true)]
    public ?string $periodicity = null;

    #[ORM\Column(name: '`create_before`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $create_before = 0;

    #[ORM\Column(name: '`next_creation_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $next_creation_date = null;

    #[ORM\ManyToOne(targetEntity: Calendar::class)]
    #[ORM\JoinColumn(name: 'calendars_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Calendar $calendars_id = null;

    #[ORM\Column(name: '`end_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $end_date = null;
}
