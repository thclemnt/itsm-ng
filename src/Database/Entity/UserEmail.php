<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_useremails')]
#[ORM\UniqueConstraint(name: 'useremails_unicity', columns: ['users_id', 'email'])]
class UserEmail
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`is_default`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_default = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`email`', type: 'string', length: 255, nullable: true)]
    public ?string $email = null;
}
