<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_changevalidations')]
class ChangeValidation
{
    #[ORM\ManyToOne(targetEntity: Change::class)]
    #[ORM\JoinColumn(name: 'changes_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?Change $changes = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?User $author = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id_validate', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?User $validator = null;

    #[ORM\Column(name: '`comment_submission`', type: 'text', nullable: true)]
    public ?string $comment_submission = null;

    #[ORM\Column(name: '`comment_validation`', type: 'text', nullable: true)]
    public ?string $comment_validation = null;

    #[ORM\Column(name: '`status`', type: 'integer', nullable: false, options: ['default' => '2'])]
    public int $status = 2;

    #[ORM\Column(name: '`submission_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $submission_date = null;

    #[ORM\Column(name: '`validation_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $validation_date = null;

    #[ORM\Column(name: '`timeline_position`', type: 'smallint', nullable: false, options: ['default' => '0'])]
    public int $timeline_position = 0;
}
