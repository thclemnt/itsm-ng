<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Licence assignment rows remain independent; no new uniqueness or deduplication. */
final class SoftwareLicenseSubjects extends SoftwareAssignmentSubjects
{
    public const PHASE = '20261011_software_license_subjects';

    protected function phase(): string
    {
        return self::PHASE;
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
