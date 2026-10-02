<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_cartridgeitems_printermodels')]
#[ORM\UniqueConstraint(name: 'cartridgeitems_printermodels_unicity', columns: ['printermodels_id', 'cartridgeitems_id'])]
class CartridgeItemPrinterModel
{
    #[ORM\ManyToOne(targetEntity: CartridgeItem::class)]
    #[ORM\JoinColumn(name: 'cartridgeitems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?CartridgeItem $cartridgeitems = null;

    #[ORM\ManyToOne(targetEntity: PrinterModel::class)]
    #[ORM\JoinColumn(name: 'printermodels_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[\itsmng\Database\Mapping\ApplicationManaged]
    public ?PrinterModel $printermodels = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;
}
