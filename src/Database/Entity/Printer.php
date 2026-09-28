<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_printers')]
class Printer
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`contact`', type: 'string', length: 255, nullable: true)]
    public ?string $contact = null;

    #[ORM\Column(name: '`contact_num`', type: 'string', length: 255, nullable: true)]
    public ?string $contact_num = null;

    #[ORM\Column(name: '`users_id_tech`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id_tech = 0;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id_tech', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Group $groups_tech = null;

    #[ORM\Column(name: '`serial`', type: 'string', length: 255, nullable: true)]
    public ?string $serial = null;

    #[ORM\Column(name: '`otherserial`', type: 'string', length: 255, nullable: true)]
    public ?string $otherserial = null;

    #[ORM\Column(name: '`have_serial`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_serial = false;

    #[ORM\Column(name: '`have_parallel`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_parallel = false;

    #[ORM\Column(name: '`have_usb`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_usb = false;

    #[ORM\Column(name: '`have_wifi`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_wifi = false;

    #[ORM\Column(name: '`have_ethernet`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $have_ethernet = false;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`memory_size`', type: 'string', length: 255, nullable: true)]
    public ?string $memory_size = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'locations_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Location $locations = null;

    #[ORM\Column(name: '`networks_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $networks_id = 0;

    #[ORM\ManyToOne(targetEntity: PrinterType::class)]
    #[ORM\JoinColumn(name: 'printertypes_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?PrinterType $printertypes = null;

    #[ORM\ManyToOne(targetEntity: PrinterModel::class)]
    #[ORM\JoinColumn(name: 'printermodels_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?PrinterModel $printermodels = null;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'manufacturers_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Manufacturer $manufacturers = null;

    #[ORM\Column(name: '`is_global`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_global = false;

    #[ORM\Column(name: '`is_deleted`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_deleted = false;

    #[ORM\Column(name: '`is_template`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_template = false;

    #[ORM\Column(name: '`template_name`', type: 'string', length: 255, nullable: true)]
    public ?string $template_name = null;

    #[ORM\Column(name: '`init_pages_counter`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $init_pages_counter = 0;

    #[ORM\Column(name: '`last_pages_counter`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $last_pages_counter = 0;

    #[ORM\Column(name: '`users_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $users_id = 0;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'groups_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Group $groups = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'states_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?State $states = null;

    #[ORM\Column(name: '`ticket_tco`', type: 'decimal', precision: 20, scale: 4, nullable: true, options: ['default' => '0.0000'])]
    public ?string $ticket_tco = '0.0000';

    #[ORM\Column(name: '`is_dynamic`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_dynamic = false;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;
}
