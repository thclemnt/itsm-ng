<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** An immutable projection preserves invalid flags and nullable legacy labels. */
final readonly class LegacyTicketStatusRow
{
    public function __construct(
        public int $id,
        public ?string $name,
        public int $weight,
        public int $activeFlag,
        public ?string $color
    ) {
        if ($id <= 0) {
            throw new \InvalidArgumentException('A status snapshot requires a persisted positive identity.');
        }
    }
}
