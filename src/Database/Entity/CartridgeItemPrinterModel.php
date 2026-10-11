<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Entity;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Mapping as ORM;
use itsmng\Database\Mapping\ApplicationManaged;
use itsmng\Database\Mapping\PlatformOptions;
use itsmng\Database\Mapping\SchemaIndex;
use itsmng\Database\Mapping\SchemaOwner;

#[ORM\Entity]
#[ORM\Table(name: 'glpi_cartridgeitems_printermodels')]
#[PlatformOptions(AbstractMySQLPlatform::class, ['engine' => 'InnoDB', 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'create_options' => []])]
#[SchemaOwner]
#[SchemaIndex('IDX_856AD7A7D0B94821', ['printermodels_id'])]
#[SchemaIndex('unicity', ['printermodels_id', 'cartridgeitems_id'], unique: true, postgresqlName: 'glpi_cartridgeitems_printermodels_unicity')]
#[SchemaIndex('cartridgeitems_id', ['cartridgeitems_id'], postgresqlName: 'glpi_cartridgeitems_printermodels_cartridgeitems_id')]
class CartridgeItemPrinterModel
{
    #[ORM\ManyToOne(targetEntity: CartridgeItem::class)]
    #[ORM\JoinColumn(name: 'cartridgeitems_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_cartridgeitems_printermodels_cartridgeitems_id', options: ['default' => 0])]
    #[ApplicationManaged]
    public ?CartridgeItem $cartridgeitems = null;

    #[ORM\ManyToOne(targetEntity: PrinterModel::class)]
    #[ORM\JoinColumn(name: 'printermodels_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_cartridgeitems_printermodels_printermodels_id', options: ['default' => 0])]
    #[ApplicationManaged]
    public ?PrinterModel $printermodels = null;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: '`id`', type: 'bigint', nullable: false)]
    public ?int $id = null;
}
