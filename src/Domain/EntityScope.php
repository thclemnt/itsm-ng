<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** The owning entity and recursion declared by an actual domain object. */
final readonly class EntityScope
{
    public function __construct(public int $entity, public bool $recursive)
    {
        if ($entity < 0) {
            throw new SoftwareAssignmentCancelled('An assignment requires a real owning entity.');
        }
    }

    public function isCompatibleWith(self $other, EntityHierarchy $hierarchy): bool
    {
        return $this->entity === $other->entity
            || ($this->recursive && $hierarchy->isAncestor($this->entity, $other->entity))
            || ($other->recursive && $hierarchy->isAncestor($other->entity, $this->entity));
    }
}
