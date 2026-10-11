<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\LinkRepository;

/** Count visible external-link definitions on the caller's selected connection. */
final class LinkCountReadOperation
{
    use PrivateReadOwnership;

    public function countForItem(string $type, EntityRestriction $scope): int
    {
        return (new LinkRepository($this->manager))->countForItem($type, $scope->wrappedCriteria());
    }
}
