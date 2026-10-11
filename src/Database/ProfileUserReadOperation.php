<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\ProfileUserRepository;
use itsmng\Database\Repository\RecordRepository;

/** One fresh domain read owns its mapping and selected connection. */
final class ProfileUserReadOperation
{
    use PrivateReadOwnership;

    /** Profile membership only; filtered notification queries retain their full criteria. */
    public function profileIds(mixed $user): array
    {
        return (new RecordRepository($this->manager))->identifiers('glpi_profiles_users', 'profiles_id', ['users_id' => $user]);
    }

    public function scopes(int $user, ?int $profile = null, ?string $right = null, int $mask = 0): array
    {
        $this->metadata('glpi_profiles_users');
        $repository = new ProfileUserRepository($this->manager);
        return $repository->scopes($user, $profile, $right, $mask);
    }
}
