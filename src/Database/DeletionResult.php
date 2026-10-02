<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Delivery failures happen after commit and must not restore a deleted model. */
final readonly class DeletionResult
{
    public function __construct(public DeletionOutcome $outcome, private array $notifications = [])
    {
    }

    public function deliverNotifications(): void
    {
        foreach ($this->notifications as [$type, $id]) {
            \QueuedNotification::forceSendFor($type, $id);
        }
    }
}
