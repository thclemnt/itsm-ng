<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

/** A preferred-address read owns its route; email mutations retain their locks. */
final class UserEmailReadOperation
{
    use PrivateReadOwnership;

    public function preferred(int $user): ?array
    {
        try {
            $metadata = $this->metadata('glpi_useremails');
            $repository = new Repository\UserEmailRepository($this->manager);
            if ($this->ownedMapping && $this->defaultIdentifiers($metadata) !== null && $metadata->isInheritanceTypeNone()) {
                return $repository->nativePreferred($user);
            }
            return $repository->preferred($user);
        } catch (\Doctrine\ORM\NoResultException) {
            // Preserve getOneOrNullResult, including mapped conversion failures.
            return null;
        }
    }
}
