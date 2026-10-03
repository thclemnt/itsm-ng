<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\DBAL\Schema\Schema;

/** Result of one live schema inspection, owned by its calling read-only phase. */
final readonly class SchemaInspection
{
    /** @param list<string> $differences */
    public function __construct(public Schema $actualSchema, public array $differences)
    {
    }
}
