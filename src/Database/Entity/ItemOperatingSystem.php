<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_operatingsystems')]
#[ORM\UniqueConstraint(name: 'items_operatingsystems_unicity', columns: ['items_id', 'itemtype', 'operatingsystems_id', 'operatingsystemarchitectures_id'])]
class ItemOperatingSystem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 255, nullable: true)]
    public ?string $itemtype = null;

    #[ORM\Column(name: '`operatingsystems_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $operatingsystems_id = 0;

    #[ORM\Column(name: '`operatingsystemversions_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $operatingsystemversions_id = 0;

    #[ORM\Column(name: '`operatingsystemservicepacks_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $operatingsystemservicepacks_id = 0;

    #[ORM\Column(name: '`operatingsystemarchitectures_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $operatingsystemarchitectures_id = 0;

    #[ORM\Column(name: '`operatingsystemkernelversions_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $operatingsystemkernelversions_id = 0;

    #[ORM\Column(name: '`license_number`', type: 'string', length: 255, nullable: true)]
    public ?string $license_number = null;

    #[ORM\Column(name: '`licenseid`', type: 'string', length: 255, nullable: true)]
    public ?string $licenseid = null;

    #[ORM\Column(name: '`operatingsystemeditions_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $operatingsystemeditions_id = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;
}
