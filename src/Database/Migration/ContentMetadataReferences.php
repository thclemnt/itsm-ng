<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

final class ContentMetadataReferences
{
    public const VERSION = '20260929_nullable_content_metadata_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::CONTENT_METADATA, 'content metadata'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::CONTENT_METADATA, 'content metadata'))->apply($connection);
    }
}
