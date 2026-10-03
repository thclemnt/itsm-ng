<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Opaque lifetime of one actual DBAL layer; it never exposes the physical handle. */
final readonly class ManagedTransactionScope
{
    /** @internal Only a managed DBAL owner supplies this captured assertion. */
    public function __construct(private \Closure $assertion)
    {
    }

    public function assertActive(): void
    {
        ($this->assertion)();
    }
}
