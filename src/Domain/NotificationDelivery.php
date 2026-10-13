<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** One selected binding, even when another binding shares its rule or template. */
final readonly class NotificationDelivery
{
    public function __construct(
        private array $notification,
        public ?int $bindingId,
        public ?string $mode,
        public ?int $templateId
    ) {
    }

    /** Existing event extensions receive the same complete notification row. */
    public function legacyRow(): array
    {
        return $this->notification + ['mode' => $this->mode, 'notificationtemplates_id' => $this->templateId];
    }
}
