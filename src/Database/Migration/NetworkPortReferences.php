<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

final class NetworkPortReferences
{
    public const VERSION = '20260929_nullable_network_port_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::NETWORK_PORT_METADATA, 'network port'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::NETWORK_PORT_METADATA, 'network port'))->apply($connection);
    }
}
