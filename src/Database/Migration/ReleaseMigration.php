<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

/** Each ORM release owns its frozen change and postconditions. History owns publication. */
interface ReleaseMigration
{
    public function version(): string;

    /** Read-only preview, against the schema produced by earlier releases. */
    public function plan(Connection $connection): array;

    /** Resumable DDL/data conversion; no release metadata publication. */
    public function apply(Connection $connection, ?callable $progress = null): void;

    /** Inspect actual frozen postconditions, regardless of internal phase receipts. */
    public function verify(Connection $connection): void;
}
