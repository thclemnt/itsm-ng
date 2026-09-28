<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_disks')]
class ItemDisk
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`device`', type: 'string', length: 255, nullable: true)]
    public ?string $device = null;

    #[ORM\Column(name: '`mountpoint`', type: 'string', length: 255, nullable: true)]
    public ?string $mountpoint = null;

    #[ORM\Column(name: '`filesystems_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $filesystems_id = 0;

    #[ORM\Column(name: '`totalsize`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $totalsize = 0;

    #[ORM\Column(name: '`freesize`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $freesize = 0;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`encryption_status`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $encryption_status = 0;

    #[ORM\Column(name: '`encryption_tool`', type: 'string', length: 255, nullable: true)]
    public ?string $encryption_tool = null;

    #[ORM\Column(name: '`encryption_algorithm`', type: 'string', length: 255, nullable: true)]
    public ?string $encryption_algorithm = null;

    #[ORM\Column(name: '`encryption_type`', type: 'string', length: 255, nullable: true)]
    public ?string $encryption_type = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
