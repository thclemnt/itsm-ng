<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_oidc_config')]
class OidcConfig
{
    #[ORM\Id]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $id = 0;

    #[ORM\Column(name: '`Provider`', type: 'string', length: 255, nullable: true)]
    public ?string $Provider = null;

    #[ORM\Column(name: '`ClientID`', type: 'string', length: 255, nullable: true)]
    public ?string $ClientID = null;

    #[ORM\Column(name: '`ClientSecret`', type: 'string', length: 255, nullable: true)]
    public ?string $ClientSecret = null;

    #[ORM\Column(name: '`is_activate`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $is_activate = 0;

    #[ORM\Column(name: '`is_forced`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $is_forced = 0;

    #[ORM\Column(name: '`scope`', type: 'string', length: 255, nullable: true)]
    public ?string $scope = null;

    #[ORM\Column(name: '`proxy`', type: 'string', length: 255, nullable: true)]
    public ?string $proxy = null;

    #[ORM\Column(name: '`cert`', type: 'string', length: 255, nullable: true)]
    public ?string $cert = null;

    #[ORM\Column(name: '`sso_link_users`', type: 'smallint', nullable: false, options: ['default' => '1'])]
    public int $sso_link_users = 1;

    #[ORM\Column(name: '`logout`', type: 'string', length: 255, nullable: true)]
    public ?string $logout = null;
}
