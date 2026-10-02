<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

/** Preserve the contact model's surname/first-name choice presentation. */
final class ContactChoiceRepository extends DropdownChoiceRepository
{
    protected function presentChoice(array $row): array
    {
        $row['name'] = ($row['name'] ?? '') . ' ' . ($row['firstname'] ?? '');
        return $row;
    }
}
