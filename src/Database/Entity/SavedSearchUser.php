<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_savedsearches_users')]
#[ORM\UniqueConstraint(name: 'savedsearches_users_unicity', columns: ['users_id', 'itemtype'])]
class SavedSearchUser
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\ManyToOne(targetEntity: SavedSearch::class)]
    #[ORM\JoinColumn(name: 'savedsearches_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?SavedSearch $savedsearches = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';
}
