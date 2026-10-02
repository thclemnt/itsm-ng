<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use itsmng\Database\ReferenceMode;

/** Immutable projection of an association and its entity-local policy. */
final readonly class MappedReference
{
    public function __construct(
        public string $association,
        public string $column,
        public string $targetTable,
        public ReferencePolicy $policy,
        public ReferenceMode $defaultMode = ReferenceMode::Explicit,
        public ?string $modeColumn = null,
        public ?int $modeLength = null,
    ) {
    }
}
