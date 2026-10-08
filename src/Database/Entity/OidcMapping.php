<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_oidc_mapping')]
class OidcMapping
{
    #[ORM\Id]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $id = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $name = '';

    #[ORM\Column(name: '`given_name`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $given_name = '';

    #[ORM\Column(name: '`family_name`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $family_name = '';

    #[ORM\Column(name: '`picture`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $picture = '';

    #[ORM\Column(name: '`email`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $email = '';

    #[ORM\Column(name: '`locale`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $locale = '';

    #[ORM\Column(name: '`phone_number`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $phone_number = '';

    #[ORM\Column(name: '`group`', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    public ?string $group = '';

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;
}
