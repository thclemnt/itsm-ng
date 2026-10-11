<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\ORM\NoResultException;
use itsmng\Database\Repository\OidcRepository;

/** One bootstrap check owns its selected connection and never caches auth state. */
final class OidcRefreshReadOperation
{
    use PrivateReadOwnership;

    public function needsRefresh(int $user): bool
    {
        if ($user <= 0) {
            return false;
        }
        try {
            $metadata = $this->metadata('glpi_oidc_users');
            $repository = new OidcRepository($this->manager);
            if ($this->ownedMapping && $this->defaultIdentifiers($metadata) !== null && $metadata->isInheritanceTypeNone()) {
                return $repository->nativeNeedsRefresh($user);
            }
            return $repository->needsRefresh($user);
        } catch (NoResultException) {
            // getOneOrNullResult treats this query/metadata/conversion exception as absence.
            return false;
        }
    }
}
