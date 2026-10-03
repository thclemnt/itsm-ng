<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Licence assignment rows remain independent; no new uniqueness or deduplication. */
final class SoftwareLicenseSubjects20261011 extends SoftwareAssignmentSubjects20261011
{
    public const VERSION = '20261011_software_license_subjects';

    protected function version(): string
    {
        return self::VERSION;
    }

    protected function table(): string
    {
        return 'glpi_items_softwarelicenses';
    }

    protected function licenses(): bool
    {
        return true;
    }
}
