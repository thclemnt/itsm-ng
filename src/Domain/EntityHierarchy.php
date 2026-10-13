<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** An operation-local graph of parent edges validated under current row locks. */
final readonly class EntityHierarchy
{
    /** @param array<int, int|null> $parents */
    public function __construct(private array $parents)
    {
        foreach (array_keys($parents) as $id) {
            $this->chain($id);
        }
    }

    /** @return array<int, int|null> */
    public function parentEdges(): array
    {
        return $this->parents;
    }

    public function contains(array $entities): bool
    {
        foreach ($entities as $id) {
            if (!array_key_exists($id, $this->parents)) {
                return false;
            }
        }
        return true;
    }

    public function isAncestor(int $ancestor, int $descendant): bool
    {
        if (!$this->contains([$ancestor, $descendant])) {
            throw new SoftwareAssignmentCancelled('The final allocation requires unreserved entity ancestry; retry the outer command.');
        }
        return in_array($ancestor, $this->chain($descendant), true);
    }

    private function chain(int $id): array
    {
        $chain = [];
        while (true) {
            if (in_array($id, $chain, true)) {
                throw new SoftwareAssignmentCancelled('Cyclic allocation entity ancestry.');
            }
            if (!array_key_exists($id, $this->parents)) {
                throw new SoftwareAssignmentCancelled('A required allocation entity ancestor is missing.');
            }
            $chain[] = $id;
            $parent = $this->parents[$id];
            if ($parent === null) {
                return $chain;
            }
            $id = $parent;
        }
    }
}
