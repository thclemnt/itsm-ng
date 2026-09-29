<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use itsmng\Database\OptionalReferences;

final class LegacyComponentModels
{
    public const VERSION = '20260929_nullable_legacy_component_models';

    public function plan(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::LEGACY_COMPONENT_MODELS, 'legacy component model'))->plan($connection);
    }

    public function apply(Connection $connection): array
    {
        return (new NullableReferences(OptionalReferences::LEGACY_COMPONENT_MODELS, 'legacy component model'))->apply($connection);
    }
}
