<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

final class FinancialReferences
{
    public const VERSION = '20260928_nullable_financial_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'FINANCIAL'), 'financial'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'FINANCIAL'), 'financial'))->apply($connection);
    }
}
