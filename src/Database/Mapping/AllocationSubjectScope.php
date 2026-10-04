<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

/** Used on the entities whose owning properties define allocation scope. */
trait AllocationSubjectScope
{
    public function allocationEntityScope(): \itsmng\Domain\EntityScope
    {
        if ($this->entities === null) {
            throw new \itsmng\Domain\SoftwareAssignmentCancelled('An allocation subject has no owning entity.');
        }
        return new \itsmng\Domain\EntityScope($this->entities->id, $this->is_recursive);
    }
}
