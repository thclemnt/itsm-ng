<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_cartridgeitems_printermodels')]
#[ORM\UniqueConstraint(name: 'cartridgeitems_printermodels_unicity', columns: ['printermodels_id', 'cartridgeitems_id'])]
class CartridgeItemPrinterModel
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'integer', nullable: false)]
    public ?int $id = null;

    #[ORM\Column(name: '`cartridgeitems_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $cartridgeitems_id = 0;

    #[ORM\Column(name: '`printermodels_id`', type: 'integer', nullable: false, options: ['default' => '0'])]
    public int $printermodels_id = 0;
}
