<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

/** Application lifecycle target of an as-yet untyped item ID; this is not an FK. */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final readonly class PolymorphicReference
{
    public function __construct(public string $target, public string $discriminator, public bool $managed = false)
    {
    }
}
