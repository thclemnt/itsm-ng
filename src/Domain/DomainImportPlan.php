<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Validated aggregate and exact identity-role changes; safe to render before any write. */
final readonly class DomainImportPlan
{
    public function __construct(
        public string $fingerprint,
        public array $counts,
        public array $records = [],
        public array $bindings = [],
        public array $profiles = [],
        public array $rights = [],
        public array $policies = [],
        public bool $alreadyImported = false,
        public ?array $sourcePlugin = null
    ) {
    }
}
