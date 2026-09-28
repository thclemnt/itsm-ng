<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_knowbaseitems_revisions')]
#[ORM\UniqueConstraint(name: 'knowbaseitems_revisions_unicity', columns: ['knowbaseitems_id', 'revision', 'language'])]
class KnowbaseItemRevision
{
    #[ORM\ManyToOne(targetEntity: KnowbaseItem::class)]
    #[ORM\JoinColumn(name: 'knowbaseitems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?KnowbaseItem $knowbaseitems = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`revision`', type: 'integer', nullable: false)]
    public int $revision = 0;

    #[ORM\Column(name: '`name`', type: 'text', nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`answer`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $answer = null;

    #[ORM\Column(name: '`language`', type: 'string', length: 10, nullable: true)]
    public ?string $language = null;

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id = 0;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
