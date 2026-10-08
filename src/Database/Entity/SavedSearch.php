<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\NativeTimestamp;
use itsmng\Database\Mapping\ReferenceKind;
use itsmng\Database\Mapping\ReferencePolicy;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_savedsearches')]
class SavedSearch
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`type`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $type = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'users_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::EmptySelection)]
    #[ApplicationManaged]
    public ?User $users = null;

    #[ORM\Column(name: '`is_private`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_private = true;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    #[ReferencePolicy(ReferenceKind::GlobalScope)]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`path`', type: 'string', length: 255, nullable: true)]
    public ?string $path = null;

    #[ORM\Column(name: '`query`', type: 'text', nullable: true)]
    public ?string $query = null;

    #[ORM\Column(name: '`last_execution_time`', type: 'integer', nullable: true)]
    public ?int $last_execution_time = null;

    #[ORM\Column(name: '`do_count`', type: 'smallint', nullable: false, options: ['default' => '2'])]
    public int $do_count = 2;

    #[ORM\Column(name: '`last_execution_date`', type: 'datetimetz', nullable: true)]
    #[NativeTimestamp]
    public ?DateTimeInterface $last_execution_date = null;

    #[ORM\Column(name: '`counter`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $counter = 0;
}
