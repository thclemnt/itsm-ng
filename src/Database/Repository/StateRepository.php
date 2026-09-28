<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\EntityRegistry;

final class StateRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $itemtype): bool
    {
        return isset(EntityRegistry::TABLES[\getTableForItemType($itemtype)]);
    }

    /** NULL state is exposed as the existing "no state" bucket, ID zero. */
    public function counts(string $itemtype, ?array $entities): array
    {
        $class = EntityRegistry::TABLES[\getTableForItemType($itemtype)] ?? throw new \InvalidArgumentException('Unmapped state item type');
        $metadata = $this->em->getClassMetadata($class);
        $query = $this->em->createQueryBuilder()->select('IDENTITY(a.states) AS states_id', 'COUNT(a.id) AS cpt')
            ->from($class, 'a')->groupBy('a.states');
        foreach (['is_deleted', 'is_template'] as $flag) {
            if ($metadata->hasField($flag)) {
                $type = $metadata->getTypeOfField($flag);
                $query->andWhere('a.' . $flag . ' = :' . $flag)
                    ->setParameter($flag, $type === Types::BOOLEAN ? false : 0, $type);
            }
        }
        if ($entities !== null) {
            $query->andWhere('IDENTITY(a.entities) IN (:entities)')->setParameter('entities', $entities ?: [-1]);
        }
        return array_map(static fn (array $row): array => ['states_id' => (int)$row['states_id'], 'cpt' => (int)$row['cpt']], $query->getQuery()->getScalarResult());
    }
}
