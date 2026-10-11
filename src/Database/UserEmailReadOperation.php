<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use itsmng\Database\Repository\UserEmailRepository;

/** A preferred-address read owns its route; email mutations retain their locks. */
final class UserEmailReadOperation
{
    use PrivateReadOwnership;

    public function preferred(int $user): ?array
    {
        $this->metadata('glpi_useremails');
        $repository = new UserEmailRepository($this->manager);
        return $repository->preferred($user);
    }
}
