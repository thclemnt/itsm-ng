<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Attribute;

/** Owning association to the object whose costs are reported. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class CostParent
{
}
