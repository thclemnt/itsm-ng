<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_oidc_mapping')]
class OidcMapping
{
    #[ORM\Id]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $id = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $name = null;

    #[ORM\Column(name: '`given_name`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $given_name = null;

    #[ORM\Column(name: '`family_name`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $family_name = null;

    #[ORM\Column(name: '`picture`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $picture = null;

    #[ORM\Column(name: '`email`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $email = null;

    #[ORM\Column(name: '`locale`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $locale = null;

    #[ORM\Column(name: '`phone_number`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $phone_number = null;

    #[ORM\Column(name: '`group`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $group = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;
}
