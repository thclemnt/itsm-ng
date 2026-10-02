<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

final class RejectedEmailReferences
{
    public const VERSION = '20260929_nullable_rejected_email_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'REJECTED_EMAIL_REFERENCES'), 'rejected email'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'REJECTED_EMAIL_REFERENCES'), 'rejected email'))->apply($connection);
    }
}
