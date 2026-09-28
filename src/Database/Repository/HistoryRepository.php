<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Log;

final class HistoryRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Called only by an item's purge lifecycle; history records have no child hooks. */
    public function deleteForItem(string $type, int $id): void
    {
        $this->em->createQueryBuilder()->delete(Log::class, 'l')
            ->where('l.itemtype = :type')->setParameter('type', $type)
            ->andWhere('l.items_id = :id')->setParameter('id', $id)
            ->getQuery()->execute();
    }
}
