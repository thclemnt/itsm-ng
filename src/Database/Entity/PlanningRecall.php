<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_planningrecalls')]
#[ORM\UniqueConstraint(name: 'planningrecalls_unicity', columns: ['itemtype', 'items_id', 'users_id'])]
class PlanningRecall
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?User $users = null;

    #[ORM\Column(name: '`before_time`', type: 'integer', nullable: false, options: ['default' => '-10'])]
    public int $before_time = -10;

    #[ORM\Column(name: '`when`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $when = null;
}
