<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Recipient-authorized browser content, independent of its queue entity's lifetime. */
final readonly class BrowserNotificationMessage
{
    public function __construct(
        public ?int $id,
        public ?string $itemtype,
        public int $itemsId,
        public ?string $title,
        public ?string $body
    ) {
    }
}
