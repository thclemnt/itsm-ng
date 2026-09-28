<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_ipaddresses')]
class IPAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`version`', type: 'smallint', nullable: true, options: ['unsigned' => true, 'default' => '0'])]
    public ?int $version = 0;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`binary_0`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $binary_0 = 0;

    #[ORM\Column(name: '`binary_1`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $binary_1 = 0;

    #[ORM\Column(name: '`binary_2`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $binary_2 = 0;

    #[ORM\Column(name: '`binary_3`', type: 'integer', nullable: false, options: ['unsigned' => true, 'default' => '0'])]
    public int $binary_3 = 0;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`mainitems_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $mainitems_id = 0;

    #[ORM\Column(name: '`mainitemtype`', type: 'string', length: 255, nullable: true)]
    public ?string $mainitemtype = null;
}
