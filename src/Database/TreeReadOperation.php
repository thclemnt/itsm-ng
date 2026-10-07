<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\TreeRepository;

/** One private tree reader; no rows or mutable manager leave its lifetime. */
final class TreeReadOperation implements ReadQueryOwner
{
    use PrivateReadOwnership;

    public function rows(string $table, array $fields, array $criteria, array|string $order = []): array
    {
        $this->metadata($table);
        return (new TreeRepository($this->manager))->rows($table, $fields, $criteria, $order, $this);
    }
}
