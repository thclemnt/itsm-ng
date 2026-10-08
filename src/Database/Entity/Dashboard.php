<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;
use itsmng\Database\Mapping\ReferenceKey;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_dashboards')]
#[ORM\UniqueConstraint(name: 'dashboard_owners', columns: ['profile_key', 'user_key'])]
class Dashboard
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 100, nullable: false)]
    public string $name = '';

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: false)]
    public string $content = '';

    #[ORM\ManyToOne(targetEntity: Profile::class)]
    #[ORM\JoinColumn(name: '`profileId`', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?Profile $profile = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: '`userId`', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?User $owner = null;

    #[ReferenceKey('profileId')]
    #[ORM\Column(type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    public ?string $profile_key = null;

    #[ReferenceKey('userId')]
    #[ORM\Column(type: 'bigint', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS')]
    public ?string $user_key = null;
}
