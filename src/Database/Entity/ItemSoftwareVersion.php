<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_softwareversions')]
#[ORM\UniqueConstraint(name: 'items_softwareversions_unicity', columns: ['itemtype', 'items_id', 'softwareversions_id'])]
class ItemSoftwareVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`softwareversions_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $softwareversions_id = 0;

    #[ORM\Column(name: '`is_deleted_item`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted_item = false;

    #[ORM\Column(name: '`is_template_item`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_template_item = false;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`date_install`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $date_install = null;
}
