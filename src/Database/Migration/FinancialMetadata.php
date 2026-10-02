<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

final class FinancialMetadata
{
    public const VERSION = '20260928_nullable_financial_metadata';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'FINANCIAL_METADATA'), 'financial metadata'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'FINANCIAL_METADATA'), 'financial metadata'))->apply($connection);
    }
}
