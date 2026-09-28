<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_itilsolutions')]
class ITILSolution
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`solutiontypes_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $solutiontypes_id = 0;

    #[ORM\Column(name: '`solutiontype_name`', type: 'string', length: 255, nullable: true)]
    public ?string $solutiontype_name = null;

    #[ORM\Column(name: '`content`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $content = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_approval`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_approval = null;

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id = 0;

    #[ORM\Column(name: '`user_name`', type: 'string', length: 255, nullable: true)]
    public ?string $user_name = null;

    #[ORM\Column(name: '`users_id_editor`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id_editor = 0;

    #[ORM\Column(name: '`users_id_approval`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id_approval = 0;

    #[ORM\Column(name: '`user_name_approval`', type: 'string', length: 255, nullable: true)]
    public ?string $user_name_approval = null;

    #[ORM\Column(name: '`status`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $status = 1;

    #[ORM\Column(name: '`itilfollowups_id`', type: 'integer', nullable: true)]
    public ?int $itilfollowups_id = null;
}
