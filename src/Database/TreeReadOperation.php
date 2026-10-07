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
        $metadata = $this->metadata($table);
        $repository = new TreeRepository($this->manager);
        if ($this->ownedMapping && $this->defaultIdentifiers($metadata) !== null && $order === []) {
            $rows = $repository->pointRows($table, $fields, $criteria);
            if ($rows !== null) {
                return $rows;
            }
        }
        return $repository->rows($table, $fields, $criteria, $order, $this);
    }
}
