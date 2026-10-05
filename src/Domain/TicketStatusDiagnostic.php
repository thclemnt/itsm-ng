<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

/** Bounded owner evidence; diagnostics contain no unrelated ticket content. */
final readonly class TicketStatusDiagnostic
{
    public function __construct(public string $code, public string $owner, public int|string|null $identity, public array $evidence = [])
    {
    }
}
