<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_requesttypes')]
class RequestType
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`is_helpdesk_default`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_helpdesk_default = false;

    #[ORM\Column(name: '`is_followup_default`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_followup_default = false;

    #[ORM\Column(name: '`is_mail_default`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_mail_default = false;

    #[ORM\Column(name: '`is_mailfollowup_default`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_mailfollowup_default = false;

    #[ORM\Column(name: '`is_active`', type: 'smallint', nullable: false, options: ['default' => '1'])]
    public int $is_active = 1;

    #[ORM\Column(name: '`is_ticketheader`', type: 'smallint', nullable: false, options: ['default' => '1'])]
    public int $is_ticketheader = 1;

    #[ORM\Column(name: '`is_itilfollowup`', type: 'smallint', nullable: false, options: ['default' => '1'])]
    public int $is_itilfollowup = 1;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
