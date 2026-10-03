<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** Capability of the DBAL owner, before a domain operation acquires a frame. */
interface ManagedTransactionConnection
{
    public function assertManagedTransaction(): void;
}
