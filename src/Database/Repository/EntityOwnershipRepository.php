<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Mapping\ReferenceKind;
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
            if (!EntityRegistry::hasPolicy($table, 'entities_id', ReferenceKind::RootEntity)) {
                continue;
            }
            $field = EntityRegistry::references($table)['entities_id']->association;
            $this->em->createQueryBuilder()->update(EntityRegistry::tables()[$table], 'r')
                ->set('r.' . $field, ':target')->where('IDENTITY(r.' . $field . ') = :source')
                ->setParameter('target', $target, Types::INTEGER)->setParameter('source', $source, Types::INTEGER)
                ->getQuery()->execute();
        }
    }
}
