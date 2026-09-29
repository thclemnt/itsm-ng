<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

final class ITILOriginReferences
{
    public const VERSION = '20260929_nullable_itil_origin_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::ITIL_ORIGINS, 'ITIL origin'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::ITIL_ORIGINS, 'ITIL origin'))->apply($connection);
    }
}
