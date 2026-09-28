<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_items_devicesimcards')]
class ItemDeviceSimcard
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`devicesimcards_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $devicesimcards_id = 0;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\Column(name: '`states_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $states_id = 0;

    #[ORM\Column(name: '`locations_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $locations_id = 0;

    #[ORM\Column(name: '`lines_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $lines_id = 0;

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id = 0;

    #[ORM\Column(name: '`groups_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $groups_id = 0;

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
