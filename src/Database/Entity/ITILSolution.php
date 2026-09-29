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

    #[ORM\ManyToOne(targetEntity: SolutionType::class)]
    #[ORM\JoinColumn(name: 'solutiontypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?SolutionType $solutiontypes = null;

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

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?User $author = null;

    #[ORM\Column(name: '`user_name`', type: 'string', length: 255, nullable: true)]
    public ?string $user_name = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_editor', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?User $editor = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_approval', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?User $approver = null;

    #[ORM\Column(name: '`user_name_approval`', type: 'string', length: 255, nullable: true)]
    public ?string $user_name_approval = null;

    #[ORM\Column(name: '`status`', type: 'integer', nullable: false, options: ['default' => '1'])]
    public int $status = 1;

    #[ORM\ManyToOne(targetEntity: ITILFollowup::class)]
    #[ORM\JoinColumn(name: 'itilfollowups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?ITILFollowup $followup = null;
}
