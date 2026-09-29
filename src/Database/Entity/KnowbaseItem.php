<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_knowbaseitems')]
class KnowbaseItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`knowbaseitemcategories_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $knowbaseitemcategories_id = 0;

    #[ORM\Column(name: '`name`', type: 'text', nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`answer`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $answer = null;

    #[ORM\Column(name: '`is_faq`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_faq = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?User $users = null;

    #[ORM\Column(name: '`view`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $view = 0;

    #[ORM\Column(name: '`date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`begin_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $begin_date = null;

    #[ORM\Column(name: '`end_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $end_date = null;
}
