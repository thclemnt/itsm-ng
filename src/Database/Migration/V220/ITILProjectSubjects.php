<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen 20261001 scope for project links to the three core ITIL subjects. */
final class ITILProjectSubjects extends ITILSubjectMigration
{
    protected function tables(): array
    {
        return ['glpi_itils_projects'];
    }
}
