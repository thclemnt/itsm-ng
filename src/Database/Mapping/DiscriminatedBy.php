<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Attribute;
use InvalidArgumentException;

/** A legacy discriminator selects this owning association; its target stays in Doctrine. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class DiscriminatedBy
{
    public function __construct(public string $discriminator, public string $legacyColumn, public array $values, public ?int $emptyValue = null, public int $minimumId = 1)
    {
        if (!$values || array_filter($values, static fn ($value) => !is_int($value) && (!is_string($value) || $value === ''))) {
            throw new InvalidArgumentException('Discriminated reference requires explicit integer or string kinds');
        }
        if ($minimumId < 0) {
            throw new InvalidArgumentException('Selected reference identifiers cannot be negative');
        }
    }
}
