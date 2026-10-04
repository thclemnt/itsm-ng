<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

/** The annotated owning parent supplies this child's cached entity scope. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class EntityScopeOwner
{
}
