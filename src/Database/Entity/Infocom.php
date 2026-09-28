<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_infocoms')]
#[ORM\UniqueConstraint(name: 'infocoms_unicity', columns: ['itemtype', 'items_id'])]
class Infocom
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`items_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $items_id = 0;

    #[ORM\Column(name: '`itemtype`', type: 'string', length: 100, nullable: false)]
    public string $itemtype = '';

    #[ORM\Column(name: '`entities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $entities_id = 0;

    #[ORM\Column(name: '`is_recursive`', type: 'boolean', nullable: false, options: ['default' => false])]
    public bool $is_recursive = false;

    #[ORM\Column(name: '`buy_date`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $buy_date = null;

    #[ORM\Column(name: '`use_date`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $use_date = null;

    #[ORM\Column(name: '`warranty_duration`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $warranty_duration = 0;

    #[ORM\Column(name: '`warranty_info`', type: 'string', length: 255, nullable: true)]
    public ?string $warranty_info = null;

    #[ORM\Column(name: '`suppliers_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $suppliers_id = 0;

    #[ORM\Column(name: '`order_number`', type: 'string', length: 255, nullable: true)]
    public ?string $order_number = null;

    #[ORM\Column(name: '`delivery_number`', type: 'string', length: 255, nullable: true)]
    public ?string $delivery_number = null;

    #[ORM\Column(name: '`immo_number`', type: 'string', length: 255, nullable: true)]
    public ?string $immo_number = null;

    #[ORM\Column(name: '`value`', type: 'decimal', precision: 20, scale: 4, nullable: false, options: ['default' => '0.0000'])]
    public string $value = '0.0000';

    #[ORM\Column(name: '`warranty_value`', type: 'decimal', precision: 20, scale: 4, nullable: false, options: ['default' => '0.0000'])]
    public string $warranty_value = '0.0000';

    #[ORM\Column(name: '`sink_time`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $sink_time = 0;

    #[ORM\Column(name: '`sink_type`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $sink_type = 0;

    #[ORM\Column(name: '`sink_coeff`', type: 'float', nullable: false, options: ['default' => '0'])]
    public float $sink_coeff = 0.0;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`bill`', type: 'string', length: 255, nullable: true)]
    public ?string $bill = null;

    #[ORM\Column(name: '`budgets_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $budgets_id = 0;

    #[ORM\Column(name: '`alert`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $alert = 0;

    #[ORM\Column(name: '`order_date`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $order_date = null;

    #[ORM\Column(name: '`delivery_date`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $delivery_date = null;

    #[ORM\Column(name: '`inventory_date`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $inventory_date = null;

    #[ORM\Column(name: '`warranty_date`', type: 'date', nullable: true)]
    public ?\DateTimeInterface $warranty_date = null;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`date_creation`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_creation = null;

    #[ORM\Column(name: '`decommission_date`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $decommission_date = null;

    #[ORM\Column(name: '`businesscriticities_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $businesscriticities_id = 0;
}
