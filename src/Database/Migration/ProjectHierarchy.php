<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

final class ProjectHierarchy
{
    public const VERSION = '20260928_nullable_project_hierarchy';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::PROJECT_HIERARCHY, 'hierarchy'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::PROJECT_HIERARCHY, 'hierarchy'))->apply($connection);
    }
}
