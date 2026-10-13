<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use itsmng\Database\CurrentReadUnavailable;
use itsmng\Database\Entity\Entity;
use itsmng\Database\TransactionOwnership;
use itsmng\Database\TransactionOwnershipMismatch;
use InvalidArgumentException;
use itsmng\Domain\EntityHierarchy;
use itsmng\Domain\SoftwareAssignmentCancelled;

/** Reserve only selected ancestry before an operation takes owning aggregate locks. */
final class EntityHierarchyRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /**
     * Reserve recursive authorization scopes after current-read admission
     * (PostgreSQL READ COMMITTED; traditional InnoDB current locking reads).
     * The caller retains its writer transaction and the referenced aggregate lock.
     *
     * @param list<int> $roots
     * @return list<int>
     */
    public function reserveDescendants(array $roots): array
    {
        if (!$roots) {
            return [];
        }
        foreach ($roots as $root) {
            if (!is_int($root) || $root < 0) {
                throw new InvalidArgumentException('A recursive grant requires a nonnegative entity identifier.');
            }
        }
        $connection = $this->em->getConnection();
        TransactionOwnership::assertManaged($connection);
        $scope = $connection->captureManagedTransactionScope();
        $level = $connection->getTransactionNestingLevel();
        $roots = array_values(array_unique($roots));
        sort($roots);
        $pending = $this->lockedDescendants($roots, false);
        if ($pending !== $roots) {
            throw new CurrentReadUnavailable('A recursive account grant refers to a missing entity.');
        }
        $seen = array_fill_keys($pending, true);
        while ($pending) {
            // Each parent is already exclusively locked. Its immediate parent
            // FK blocks a concurrent child insert/reparent until this frame ends.
            $children = $this->lockedDescendants($pending, true);
            $pending = [];
            foreach ($children as $child) {
                if (!isset($seen[$child])) {
                    $seen[$child] = true;
                    $pending[] = $child;
                }
            }
        }
        $scope->assertActive();
        if ($connection->getTransactionNestingLevel() !== $level) {
            throw new TransactionOwnershipMismatch('Recursive scope reservation changed the caller transaction depth.');
        }
        $entities = array_keys($seen);
        sort($entities);
        return $entities;
    }

    /** Fixed Entity traversal; every value keeps the mapped identifier SQL converter. */
    private function lockedDescendants(array $ids, bool $children): array
    {
        $query = $this->em->createQueryBuilder()->select('e.id AS id')->from(Entity::class, 'e');
        $type = $this->em->getClassMetadata(Entity::class)->getTypeOfField('id');
        $parameters = [];
        foreach ($ids as $index => $id) {
            $name = 'entity_' . $index;
            $parameters[] = ':' . $name;
            $query->setParameter($name, $id, $type);
        }
        $query->where(($children ? 'IDENTITY(e.parent)' : 'e.id') . ' IN (' . implode(', ', $parameters) . ')');
        $rows = $query->orderBy('e.id')->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getScalarResult();
        return array_map(static fn (array $row): int => (int)$row['id'], $rows);
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
