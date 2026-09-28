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

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_active = false;

    #[ORM\Column(name: '`tickettemplates_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $tickettemplates_id = 0;

    #[ORM\Column(name: '`begin_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $begin_date = null;

    #[ORM\Column(name: '`periodicity`', type: 'string', length: 255, nullable: true)]
    public ?string $periodicity = null;

    #[ORM\Column(name: '`create_before`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $create_before = 0;

    #[ORM\Column(name: '`next_creation_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $next_creation_date = null;

    #[ORM\Column(name: '`calendars_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $calendars_id = 0;

    #[ORM\Column(name: '`end_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $end_date = null;
}
