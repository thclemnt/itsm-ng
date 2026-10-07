<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** One fresh domain read owns its mapping and selected connection. */
final class ProfileUserReadOperation implements ReadQueryOwner
{
    use PrivateReadOwnership;

    public function scopes(int $user, ?int $profile = null, ?string $right = null, int $mask = 0): array
    {
        $metadata = $this->metadata('glpi_profiles_users');
        $repository = new Repository\ProfileUserRepository($this->manager);
        if ($this->ownedMapping && $this->defaultIdentifiers($metadata) !== null && $metadata->isInheritanceTypeNone()) {
            return $repository->nativeScopes($user, $profile, $right, $mask);
        }
        return $repository->scopes($user, $profile, $right, $mask);
    }
}
