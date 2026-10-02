<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

final class ArticleCategoryReferences
{
    public const VERSION = '20260929_nullable_article_categories_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'ARTICLE_CATEGORIES'), 'article category'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'ARTICLE_CATEGORIES'), 'article category'))->apply($connection);
    }
}
