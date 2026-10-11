<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Attribute;

/** The owning model's lifecycle handles this link instead of generic replacement. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ApplicationManaged
{
}
