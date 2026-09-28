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

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`filename`', type: 'string', length: 255, nullable: true)]
    public ?string $filename = null;

    #[ORM\Column(name: '`filepath`', type: 'string', length: 255, nullable: true)]
    public ?string $filepath = null;

    #[ORM\Column(name: '`documentcategories_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $documentcategories_id = 0;

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

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id = 0;

    #[ORM\Column(name: '`tickets_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $tickets_id = 0;

    #[ORM\Column(name: '`sha1sum`', type: 'string', length: 40, nullable: true, options: ['fixed' => true])]
    public ?string $sha1sum = null;

    #[ORM\Column(name: '`is_blacklisted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_blacklisted = false;

    #[ORM\Column(name: '`tag`', type: 'string', length: 255, nullable: true)]
    public ?string $tag = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
