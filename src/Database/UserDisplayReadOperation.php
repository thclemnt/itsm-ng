<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\ORM\NoResultException;
use itsmng\Database\Repository\UserRepository;

/** One fresh domain read owns its mapping and selected connection. */
final class UserDisplayReadOperation
{
    use PrivateReadOwnership;

    public function timelinePreferences(int $user): array
    {
        try {
            $this->metadata('glpi_users');
            $repository = new UserRepository($this->manager);
            return $repository->timelinePreferences($user);
        } catch (NoResultException) {
            return [];
        }
    }

    public function displayData(int $user): ?array
    {
        $this->metadata('glpi_users');
        $repository = new UserRepository($this->manager);
        return $repository->displayData($user);
    }
}
