<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

final class PlanningMetadataReferences
{
    public const VERSION = '20260929_nullable_planning_metadata_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'PLANNING_METADATA'), 'planning metadata'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'PLANNING_METADATA'), 'planning metadata'))->apply($connection);
    }
}
