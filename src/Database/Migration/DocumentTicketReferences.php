<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

final class DocumentTicketReferences
{
    public const VERSION = '20260929_nullable_document_ticket_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::DOCUMENT_TICKETS, 'document ticket'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::DOCUMENT_TICKETS, 'document ticket'))->apply($connection);
    }
}
