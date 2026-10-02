<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

final class ContactLineReferences
{
    public const VERSION = '20260929_nullable_contact_lines_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'CONTACT_LINE_METADATA'), 'contact and line'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'CONTACT_LINE_METADATA'), 'contact and line'))->apply($connection);
    }
}
