<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

final class GroupReferences
{
    public const VERSION = '20260928_nullable_group_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::GROUPS, 'group'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::GROUPS, 'group'))->apply($connection);
    }
}
