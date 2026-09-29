<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_rssfeeds')]
class RSSFeed
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?User $users = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`url`', type: 'text', nullable: true)]
    public ?string $url = null;

    #[ORM\Column(name: '`refresh_rate`', type: 'integer', nullable: false, options: ['default' => '86400'])]
    public int $refresh_rate = 86400;

    #[ORM\Column(name: '`max_items`', type: 'integer', nullable: false, options: ['default' => '20'])]
    public int $max_items = 20;

    #[ORM\Column(name: '`have_error`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_error = false;

    #[ORM\Column(name: '`is_active`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_active = false;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
