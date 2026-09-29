<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

final class SavedSearchReferences
{
    public const VERSION = '20260929_nullable_saved_search_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::SAVED_SEARCHES, 'saved-search owner'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::SAVED_SEARCHES, 'saved-search owner'))->apply($connection);
    }
}
