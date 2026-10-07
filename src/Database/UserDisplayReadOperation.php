<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** One fresh domain read owns its mapping and selected connection. */
final class UserDisplayReadOperation
{
    use PrivateReadOwnership;

    public function displayData(int $user): ?array
    {
        $metadata = $this->metadata('glpi_users');
        $repository = new Repository\UserRepository($this->manager);
        if ($this->ownedMapping && $this->defaultIdentifiers($metadata) !== null && $metadata->isInheritanceTypeNone()) {
            return $repository->nativeDisplayData($user);
        }
        return $repository->displayData($user);
    }
}
