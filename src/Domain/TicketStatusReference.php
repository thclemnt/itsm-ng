<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Exact before/meaning pair for a Ticket-owned status; not a business transition. */
final readonly class TicketStatusReference
{
    public function __construct(public int $ticketId, public int $legacyCode, public ?int $statusId, public bool $inactive)
    {
    }
}
