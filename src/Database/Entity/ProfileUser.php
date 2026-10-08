<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_profiles_users')]
class ProfileUser
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\ManyToOne(targetEntity: Profile::class)]
    #[ORM\JoinColumn(name: 'profiles_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?Profile $profiles = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public ?Entity $entities = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_recursive = true;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`is_default_profile`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_default_profile = false;
}
