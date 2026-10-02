<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

final class GlobalEntityScopes
{
    public const VERSION = '20260930_global_entity_scopes';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('global', 'RELATIONS'), 'global configuration entity', -1))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('global', 'RELATIONS'), 'global configuration entity', -1))->apply($connection);
    }
}
