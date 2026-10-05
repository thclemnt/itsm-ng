<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen installation subjects retain their original version uniqueness. */
final class SoftwareInstallationSubjects extends SoftwareAssignmentSubjects
{
    public const PHASE = '20261011_software_installation_subjects';

    protected function phase(): string
    {
        return self::PHASE;
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
