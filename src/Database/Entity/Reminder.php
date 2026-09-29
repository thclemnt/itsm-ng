<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_reminders')]
#[ORM\UniqueConstraint(name: 'reminders_uuid', columns: ['uuid'])]
class Reminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`uuid`', type: 'string', length: 255, nullable: true)]
    public ?string $uuid = null;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?User $users = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`text`', type: 'text', nullable: true)]
    public ?string $text = null;

    #[ORM\Column(name: '`begin`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $begin = null;

    #[ORM\Column(name: '`end`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $end = null;

    #[ORM\Column(name: '`is_planned`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_planned = false;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`state`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $state = 0;

    #[ORM\Column(name: '`begin_view_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $begin_view_date = null;

    #[ORM\Column(name: '`end_view_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $end_view_date = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
