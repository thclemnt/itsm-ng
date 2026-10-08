<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_links_itemtypes')]
#[ORM\UniqueConstraint(name: 'links_itemtypes_unicity', columns: ['itemtype', 'links_id'])]
class LinkItemtype
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Link::class)]
    #[ORM\JoinColumn(name: 'links_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApplicationManaged]
    public Link $links;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';
}
