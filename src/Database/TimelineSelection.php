<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** One policy calculation feeds both rendered events and their scalar counts. */
final readonly class TimelineSelection
{
    public function __construct(
        public array $criteria,
        public bool $followupRestricted,
        public mixed $followupAuthor,
        public bool $taskRestricted,
        public mixed $taskAuthor,
    ) {
    }
}
