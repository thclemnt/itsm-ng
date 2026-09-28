<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_transfers')]
class Transfer
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`name`', type: 'string', length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(name: '`keep_ticket`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_ticket = 0;

    #[ORM\Column(name: '`keep_networklink`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_networklink = 0;

    #[ORM\Column(name: '`keep_reservation`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_reservation = 0;

    #[ORM\Column(name: '`keep_history`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_history = 0;

    #[ORM\Column(name: '`keep_device`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_device = 0;

    #[ORM\Column(name: '`keep_infocom`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_infocom = 0;

    #[ORM\Column(name: '`keep_dc_monitor`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_dc_monitor = 0;

    #[ORM\Column(name: '`clean_dc_monitor`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $clean_dc_monitor = 0;

    #[ORM\Column(name: '`keep_dc_phone`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_dc_phone = 0;

    #[ORM\Column(name: '`clean_dc_phone`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $clean_dc_phone = 0;

    #[ORM\Column(name: '`keep_dc_peripheral`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_dc_peripheral = 0;

    #[ORM\Column(name: '`clean_dc_peripheral`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $clean_dc_peripheral = 0;

    #[ORM\Column(name: '`keep_dc_printer`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_dc_printer = 0;

    #[ORM\Column(name: '`clean_dc_printer`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $clean_dc_printer = 0;

    #[ORM\Column(name: '`keep_supplier`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_supplier = 0;

    #[ORM\Column(name: '`clean_supplier`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $clean_supplier = 0;

    #[ORM\Column(name: '`keep_contact`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_contact = 0;

    #[ORM\Column(name: '`clean_contact`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $clean_contact = 0;

    #[ORM\Column(name: '`keep_contract`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_contract = 0;

    #[ORM\Column(name: '`clean_contract`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $clean_contract = 0;

    #[ORM\Column(name: '`keep_software`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_software = 0;

    #[ORM\Column(name: '`clean_software`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $clean_software = 0;

    #[ORM\Column(name: '`keep_document`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_document = 0;

    #[ORM\Column(name: '`clean_document`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $clean_document = 0;

    #[ORM\Column(name: '`keep_cartridgeitem`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_cartridgeitem = 0;

    #[ORM\Column(name: '`clean_cartridgeitem`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $clean_cartridgeitem = 0;

    #[ORM\Column(name: '`keep_cartridge`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_cartridge = 0;

    #[ORM\Column(name: '`keep_consumable`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_consumable = 0;

    #[ORM\Column(name: '`date_mod`', type: 'datetimetz', nullable: true)]
    public ?\DateTimeInterface $date_mod = null;

    #[ORM\Column(name: '`comment`', type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(name: '`keep_disk`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $keep_disk = 0;
}
