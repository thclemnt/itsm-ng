<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_dropdowntranslations')]
#[ORM\UniqueConstraint(name: 'dropdowntranslations_unicity', columns: ['itemtype', 'items_id', 'language', 'field'])]
class DropdownTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'bigint', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\Column(name: '`language`', type: 'string', length: 10, nullable: true)]
    public ?string $language = null;

    #[ORM\Column(name: '`field`', type: 'string', length: 100, nullable: true)]
    public ?string $field = null;

    #[ORM\Column(name: '`value`', type: 'text', nullable: true)]
    public ?string $value = null;
}
