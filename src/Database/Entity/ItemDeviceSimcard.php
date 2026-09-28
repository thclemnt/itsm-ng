<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_devicesimcards')]
class ItemDeviceSimcard
{
    #[ORM\ManyToOne(targetEntity: DeviceSimcard::class)]
    #[ORM\JoinColumn(name: 'devicesimcards_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    public ?DeviceSimcard $devicesimcards = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\ManyToOne(targetEntity: Entity::class)]
    #[ORM\JoinColumn(name: 'entities_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', options: ['default' => 0])]
    public ?Entity $entities = null;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?State $states = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Location $locations = null;

    #[ORM\Column(name: '`lines_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $lines_id = 0;

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id = 0;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Group $groups = null;

    #[ORM\Column(name: '`pin`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $pin = '';

    #[ORM\Column(name: '`pin2`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $pin2 = '';

    #[ORM\Column(name: '`puk`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $puk = '';

    #[ORM\Column(name: '`puk2`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $puk2 = '';

    #[ORM\Column(name: '`msin`', type: 'string', length: 255, nullable: false, options: ['default' => ''])]
    public string $msin = '';
}
