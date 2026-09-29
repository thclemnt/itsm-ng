<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

final class NetworkNameReferences
{
    public const VERSION = '20260929_nullable_network_name_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::NETWORK_NAMES, 'network name'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::NETWORK_NAMES, 'network name'))->apply($connection);
    }
}
