<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_documents')]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`filename`', type: 'string', length: 255, nullable: true)]
    public ?string $filename = null;

    #[ORM\Column(name: '`filepath`', type: 'string', length: 255, nullable: true)]
    public ?string $filepath = null;

    #[ORM\ManyToOne(targetEntity: DocumentCategory::class)]
    #[ORM\JoinColumn(name: 'documentcategories_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?DocumentCategory $documentcategories = null;

    #[ORM\Column(name: '`mime`', type: 'string', length: 255, nullable: true)]
    public ?string $mime = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`link`', type: 'string', length: 255, nullable: true)]
    public ?string $link = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?User $users = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'tickets_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Ticket $tickets = null;

    #[ORM\Column(name: '`sha1sum`', type: 'string', length: 40, nullable: true, options: ['fixed' => true])]
    public ?string $sha1sum = null;

    #[ORM\Column(name: '`is_blacklisted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_blacklisted = false;

    #[ORM\Column(name: '`tag`', type: 'string', length: 255, nullable: true)]
    public ?string $tag = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
