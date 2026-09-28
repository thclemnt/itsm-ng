<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\EntityOwnership;
use itsmng\Database\EntityRegistry;

final class EntityOwnershipRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Repair denormalized owners after the owning models have run their lifecycle hooks. */
    public function moveCachedOwners(array $tables, int $source, int $target): void
    {
        foreach ($tables as $table) {
            if (!isset(EntityOwnership::RELATIONS[$table])) {
                continue;
            }
            $this->em->createQueryBuilder()->update(EntityRegistry::TABLES[$table], 'r')
                ->set('r.entities', ':target')->where('IDENTITY(r.entities) = :source')
                ->setParameter('target', $target, Types::INTEGER)->setParameter('source', $source, Types::INTEGER)
                ->getQuery()->execute();
        }
    }
}
