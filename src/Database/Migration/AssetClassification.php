<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

final class AssetClassification
{
    public const VERSION = '20260928_nullable_asset_classification';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::ASSET_CLASSIFICATION, 'asset classification'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::ASSET_CLASSIFICATION, 'asset classification'))->apply($connection);
    }
}
