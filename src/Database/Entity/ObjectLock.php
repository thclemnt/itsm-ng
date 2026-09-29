<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_objectlocks')]
#[ORM\UniqueConstraint(name: 'objectlocks_item', columns: ['itemtype', 'items_id'])]
class ObjectLock
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false)]
    public int $items_id = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?User $users = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: false, options: ['default' => 'CURRENT_TIMESTAMP'])]
    public ?\DateTimeInterface $date_mod = null;
}
