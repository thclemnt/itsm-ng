<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_authldapreplicates')]
class AuthLdapReplicate
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AuthLDAP::class)]
    #[ORM\JoinColumn(name: 'authldaps_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public AuthLDAP $authldaps;

    #[ORM\Column(name: '`host`', type: 'string', length: 255, nullable: true)]
    public ?string $host = null;

    #[ORM\Column(name: '`port`', type: 'integer', nullable: false, options: ['default' => '389'])]
    public int $port = 389;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;
}
