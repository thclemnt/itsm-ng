<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

/** Frozen 20261001 scope for typed followup and solution subjects. */
final class ITILSubjects extends ITILSubjectMigration
{
    protected function tables(): array
    {
        return ['glpi_itilfollowups', 'glpi_itilsolutions'];
    }
}
