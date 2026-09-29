<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

final class ServiceLevelReferences
{
    public const VERSION = '20260929_nullable_service_level_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::SERVICE_LEVELS, 'ticket service-level'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::SERVICE_LEVELS, 'ticket service-level'))->apply($connection);
    }
}
