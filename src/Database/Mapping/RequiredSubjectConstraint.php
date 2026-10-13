<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Attribute;

/** Stable name for the required subject CHECK declared by an owning record. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiredSubjectConstraint
{
    public function __construct(public string $suffix)
    {
    }
}
