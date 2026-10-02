<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

/** Recursion checks follow the asset type stored beside this polymorphic ID. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class VirtualAssetLink
{
    public function __construct(public string $discriminator)
    {
    }
}
