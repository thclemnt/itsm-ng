<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Entity;
use itsmng\Domain\EntityHierarchy;
use itsmng\Domain\SoftwareAssignmentCancelled;

/** Reserve only selected ancestry before an operation takes owning aggregate locks. */
final class EntityHierarchyRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function reserve(array $entities): EntityHierarchy
    {
        $parents = [];
        $pending = array_values(array_unique(array_map('intval', $entities)));
        while ($pending) {
            sort($pending);
            $rows = $this->parents($pending, false);
            if (count($rows) !== count($pending)) {
                throw new SoftwareAssignmentCancelled('A required allocation entity is missing.');
            }
            $pending = [];
            foreach ($rows as $id => $parent) {
                $parents[$id] = $parent;
                if ($parent !== null && !array_key_exists($parent, $parents)) {
                    $pending[] = $parent;
                }
            }
            $pending = array_values(array_unique($pending));
        }
        ksort($parents);
        // Missing ancestors and cycles are diagnosed before acquiring the graph.
        new EntityHierarchy($parents);
        if ($this->parents(array_keys($parents), true) !== $parents) {
            throw new SoftwareAssignmentCancelled('Allocation entity ancestry changed before reservation; retry the outer command.');
        }
        return new EntityHierarchy($parents);
    }

    /** Existing reserved rows must still represent the same actual parent edges. */
    public function validate(EntityHierarchy $hierarchy): void
    {
        $parents = $hierarchy->parentEdges();
        if ($this->parents(array_keys($parents), true) !== $parents) {
            throw new SoftwareAssignmentCancelled('A lifecycle callback changed reserved allocation ancestry.');
        }
    }

    private function parents(array $ids, bool $current): array
    {
        if (!$ids) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()->select('e.id AS id', 'IDENTITY(e.parent) AS parent')
            ->from(Entity::class, 'e')->where('e.id IN (:ids)')->setParameter('ids', $ids)
            ->orderBy('e.id')->getQuery()->setLockMode($current ? LockMode::PESSIMISTIC_READ : LockMode::NONE)
            ->getScalarResult();
        $parents = [];
        foreach ($rows as $row) {
            $parents[(int)$row['id']] = $row['parent'] === null ? null : (int)$row['parent'];
        }
        return $parents;
    }
}
