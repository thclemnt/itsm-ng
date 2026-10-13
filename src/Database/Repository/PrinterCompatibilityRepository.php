<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

final class PrinterCompatibilityRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function add(int $cartridge, int $model): bool
    {
        if ($cartridge <= 0 || $model <= 0) {
            return false;
        }
        if ($this->em->getRepository(Entity\CartridgeItemPrinterModel::class)->findOneBy(['cartridgeitems' => $cartridge, 'printermodels' => $model])) {
            return true;
        }
        $item = $this->em->find(Entity\CartridgeItem::class, $cartridge);
        $printerModel = $this->em->find(Entity\PrinterModel::class, $model);
        if ($item === null || $printerModel === null) {
            return false;
        }
        $link = new Entity\CartridgeItemPrinterModel();
        $link->cartridgeitems = $item;
        $link->printermodels = $printerModel;
        $this->em->persist($link);
        $this->em->flush();
        return true;
    }
}
