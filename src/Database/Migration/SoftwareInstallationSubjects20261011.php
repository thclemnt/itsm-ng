<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen installation subjects retain their original version uniqueness. */
final class SoftwareInstallationSubjects20261011 extends SoftwareAssignmentSubjects20261011
{
    public const VERSION = '20261011_software_installation_subjects';

    protected function version(): string
    {
        return self::VERSION;
    }

    protected function table(): string
    {
        return 'glpi_items_softwareversions';
    }

    protected function licenses(): bool
    {
        return false;
    }
}
