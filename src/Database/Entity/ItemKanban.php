<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_kanbans')]
#[ORM\UniqueConstraint(name: 'items_kanbans_unicity', columns: ['itemtype', 'items_id', 'users_id'])]
class ItemKanban
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: true)]
    public ?int $items_id = null;

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false)]
    public int $users_id = 0;

    #[ORM\Column(name: '`state`', type: 'text', nullable: true)]
    public ?string $state = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
