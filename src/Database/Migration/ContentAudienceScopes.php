<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\ContentAudienceScopes as Scopes;

final class ContentAudienceScopes
{
    public const VERSION = '20260930_content_audience_scopes';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(Scopes::RELATIONS, 'content audience entity', -1))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(Scopes::RELATIONS, 'content audience entity', -1))->apply($connection);
    }
}
