<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;

final class ITILClassificationReferences
{
    public const VERSION = '20260929_nullable_itil_classification_references';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'ITIL_CLASSIFICATION'), 'ITIL classification'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(ReferenceHistory::get('optional', 'ITIL_CLASSIFICATION'), 'ITIL classification'))->apply($connection);
    }
}
