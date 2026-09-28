<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_oidc_users')]
class OidcUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`user_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $user_id = 0;

    #[ORM\Column(name: '`update`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $update = 0;
}
