<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

final class AssetUserReferences
{
    public const VERSION = '20260929_nullable_asset_user_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'ASSET_USERS'), 'asset user'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'ASSET_USERS'), 'asset user'))->apply($connection);
    }
}
