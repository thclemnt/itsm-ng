<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_states')]
#[ORM\UniqueConstraint(name: 'states_unicity', columns: ['states_id', 'name'])]
class State
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`states_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $states_id = 0;

    #[ORM\Column(name: '`completename`', type: 'text', nullable: true)]
    public ?string $completename = null;

    #[ORM\Column(name: '`level`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $level = 0;

    #[ORM\Column(name: '`ancestors_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $ancestors_cache = null;

    #[ORM\Column(name: '`sons_cache`', type: 'text', length: 4294967295, nullable: true)]
    public ?string $sons_cache = null;

    #[ORM\Column(name: '`is_visible_computer`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_computer = true;

    #[ORM\Column(name: '`is_visible_monitor`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_monitor = true;

    #[ORM\Column(name: '`is_visible_networkequipment`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_networkequipment = true;

    #[ORM\Column(name: '`is_visible_peripheral`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_peripheral = true;

    #[ORM\Column(name: '`is_visible_phone`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_phone = true;

    #[ORM\Column(name: '`is_visible_printer`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_printer = true;

    #[ORM\Column(name: '`is_visible_softwareversion`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_softwareversion = true;

    #[ORM\Column(name: '`is_visible_softwarelicense`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_softwarelicense = true;

    #[ORM\Column(name: '`is_visible_line`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_line = true;

    #[ORM\Column(name: '`is_visible_certificate`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_certificate = true;

    #[ORM\Column(name: '`is_visible_rack`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_rack = true;

    #[ORM\Column(name: '`is_visible_passivedcequipment`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_passivedcequipment = true;

    #[ORM\Column(name: '`is_visible_enclosure`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_enclosure = true;

    #[ORM\Column(name: '`is_visible_pdu`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_pdu = true;

    #[ORM\Column(name: '`is_visible_cluster`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_cluster = true;

    #[ORM\Column(name: '`is_visible_contract`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_contract = true;

    #[ORM\Column(name: '`is_visible_appliance`', type: 'boolean', nullable: false, options: ['default' => true])]
    public bool $is_visible_appliance = true;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
