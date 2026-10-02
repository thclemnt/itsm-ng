<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

/** Semantics live beside the owning Doctrine association, which owns its target/type. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class ReferencePolicy
{
    public function __construct(
        public ReferenceKind $kind,
        public ?string $modeProperty = null,
        public bool $emptyZero = true,
        public ?UserReferenceAction $userPurge = null,
    ) {
        if (($kind === ReferenceKind::Inherited) !== ($modeProperty !== null)) {
            throw new \InvalidArgumentException('Only inherited references declare a mode property');
        }
    }
}
