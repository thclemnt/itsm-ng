<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Mapping;

use Doctrine\DBAL\Platforms\AbstractPlatform;

/** A generated identity key for a nullable reference, including mixed-case legacy columns. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ReferenceKey
{
    public function __construct(public readonly string $column)
    {
    }

    public function declaration(AbstractPlatform $platform): string
    {
        return 'BIGINT GENERATED ALWAYS AS (COALESCE(' . $platform->quoteIdentifier($this->column) . ', 0)) STORED';
    }
}
