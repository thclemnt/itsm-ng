<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

final class LocationReferences
{
    public const VERSION = '20260928_nullable_location_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'LOCATIONS'), 'location'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'LOCATIONS'), 'location'))->apply($connection);
    }
}
