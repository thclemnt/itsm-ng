<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

final class PersonalContentOwners
{
    public const VERSION = '20260929_nullable_personal_content_owner_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::PERSONAL_CONTENT_OWNERS, 'personal content owner'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::PERSONAL_CONTENT_OWNERS, 'personal content owner'))->apply($connection);
    }
}
