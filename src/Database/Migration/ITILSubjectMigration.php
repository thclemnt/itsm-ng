<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen subject targets shared by the versioned ITIL migration scopes. */
abstract class ITILSubjectMigration extends TypedItemMigration
{
    protected static function targets(): array
    {
        return ['Ticket' => 'tickets', 'Problem' => 'problems', 'Change' => 'changes'];
    }

    protected static function constraintName(string $table): string
    {
        return $table . '_subject_kind';
    }
}
