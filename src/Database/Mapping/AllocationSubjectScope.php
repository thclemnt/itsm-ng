<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use itsmng\Domain\EntityScope;
use itsmng\Domain\SoftwareAssignmentCancelled;

/** Used on the entities whose owning properties define allocation scope. */
trait AllocationSubjectScope
{
    public function allocationEntityScope(): EntityScope
    {
        if ($this->entities === null) {
            throw new SoftwareAssignmentCancelled('An allocation subject has no owning entity.');
        }
        return new EntityScope($this->entities->id, $this->is_recursive);
    }
}
