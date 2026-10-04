<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Unproven rollback cannot authorize rewinding persisted model/session views. */
final class MutationRollbackFailure extends MutationCleanupFailure
{
    public function __construct(\Throwable $primary, \Throwable $cleanup)
    {
        parent::__construct($primary, $cleanup, rollbackUnproven: true);
    }
}
