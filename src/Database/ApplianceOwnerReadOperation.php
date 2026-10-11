<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\ApplianceAssetRepository;

/** One reverse appliance count retains the caller's selected route and scope. */
final class ApplianceOwnerReadOperation
{
    use PrivateReadOwnership;

    public function ownerCount(string $kind, int $asset, array $criteria): int
    {
        return (new ApplianceAssetRepository($this->manager))->ownerCount($kind, $asset, $criteria);
    }
}
