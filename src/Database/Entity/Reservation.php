<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_reservations')]
class Reservation
{
    #[ORM\ManyToOne(targetEntity: ReservationItem::class)]
    #[ORM\JoinColumn(name: 'reservationitems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?ReservationItem $reservationitems = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`begin`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $begin = null;

    #[ORM\Column(name: '`end`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $end = null;

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id = 0;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`group`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $group = 0;
}
