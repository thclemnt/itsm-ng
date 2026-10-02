<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Appliance;

/** Validated source graph and identity-adoption effects, before any destination write. */
final readonly class ApplianceImportPlan
{
    public function __construct(
        public string $fingerprint,
        public array $counts,
        public array $records = [],
        public array $bindings = [],
        public array $audits = [],
        public array $profiles = [],
        public bool $alreadyImported = false
    ) {
    }
}
