<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\NativeTimestamp;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_documenttypes')]
#[ORM\UniqueConstraint(name: 'documenttypes_unicity', columns: ['ext'])]
class DocumentType
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`ext`', type: 'string', length: 255, nullable: true)]
    public ?string $ext = null;

    #[ORM\Column(name: '`icon`', type: 'string', length: 255, nullable: true)]
    public ?string $icon = null;

    #[ORM\Column(name: '`mime`', type: 'string', length: 255, nullable: true)]
    public ?string $mime = null;

    #[ORM\Column(name: '`is_uploadable`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_uploadable = true;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $date_creation = null;
}
