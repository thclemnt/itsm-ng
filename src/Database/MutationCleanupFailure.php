<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use RuntimeException;
use Throwable;

/** Keep the actual first failure and the secondary ownership cleanup inspectable. */
class MutationCleanupFailure extends RuntimeException
{
    public function __construct(
        public readonly Throwable $primary,
        public readonly Throwable $cleanup,
        public readonly bool $rollbackUnproven = false
    ) {
        parent::__construct('Mutation cleanup failed; inspect the primary and cleanup failures. Primary: '
            . $primary->getMessage(), 0, $primary);
    }
}
