<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

final class NetworkConnectionRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Distinct asset IDs by type across both cable orientations. */
    public function peers(string $itemtype, int $id): array
    {
        $peers = [];
        foreach ([1 => 2, 2 => 1] as $local => $remote) {
            $query = $this->em->createQueryBuilder()
                ->select('remote.itemtype AS itemtype', 'remote.items_id AS item_id')->distinct()
                ->from(Entity\NetworkPortNetworkPort::class, 'wire')
                ->innerJoin('wire.networkports_id_' . $local, 'local')
                ->innerJoin('wire.networkports_id_' . $remote, 'remote')
                ->where('local.itemtype = :type AND local.items_id = :id')
                ->setParameter('type', $itemtype, Types::STRING)->setParameter('id', $id, Types::INTEGER);
            foreach ($query->getQuery()->getScalarResult() as $row) {
                $target = (int)$row['item_id'];
                $peers[$row['itemtype']][$target] = $target;
            }
        }
        return $peers;
    }
}
